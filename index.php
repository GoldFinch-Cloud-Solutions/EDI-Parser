<?php
/**
 * WooCommerce Orders XML Parser API
 * 
 * Usage: 
 * GET  /parse_orders_api.php?file=wc-orders.xml          (Parse single file)
 * GET  /parse_orders_api.php?action=list                 (List all XML files from local/SFTP)
 * GET  /parse_orders_api.php?action=parseAll             (Parse all XML files from local/SFTP)
 * GET  /parse_orders_api.php?action=listSftp             (List files on SFTP server)
 * GET  /parse_orders_api.php?action=downloadSftp         (Download all files from SFTP)
 * GET  /parse_orders_api.php?action=parseSftp            (Download and parse all files from SFTP)
 * POST /parse_orders_api.php (with single file upload)
 * POST /parse_orders_api.php (with multiple files upload using field name "files[]")
 * POST /parse_orders_api.php (with XML content in body)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle OPTIONS request for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ============================================
// SFTP Configuration (Username/Password Authentication)
// ============================================
$SFTP_CONFIG = [
    'host' => getenv('SFTP_HOST') ?: 'virginia.sftptogo.com',
    'port' => getenv('SFTP_PORT') ?: 22,
    'username' => getenv('SFTP_USERNAME') ?: 'def00441166779c394b1ebf405d60a',
    'password' => getenv('SFTP_PASSWORD') ?: '7Ivut003QohHdnzxzsPzKbkTbGGWHj',
    'remote_path' => getenv('SFTP_REMOTE_PATH') ?: '/EDI_Orders'
];

/**
 * SFTP Connection Class
 */
class SFTPConnection {
    private $connection;
    private $sftp;
    private $config;
    
    public function __construct($config) {
        $this->config = $config;
    }
    
    /**
     * Connect to SFTP server using phpseclib, cURL, or native SSH2
     */
    public function connect() {
        // Try phpseclib first (most reliable)
        if (class_exists('phpseclib3\Net\SFTP')) {
            return $this->connectPhpseclib3();
        } elseif (class_exists('phpseclib\Net\SFTP')) {
            return $this->connectPhpseclib2();
        } 
        // Try cURL with SFTP support
        elseif (function_exists('curl_init') && $this->checkCurlSFTPSupport()) {
            return $this->connectCurl();
        }
        // Try native SSH2 extension
        elseif (function_exists('ssh2_connect')) {
            return $this->connectNative();
        } else {
            throw new Exception("No SFTP library available. Install phpseclib (composer require phpseclib/phpseclib) or enable cURL with SFTP support or SSH2 extension");
        }
    }
    
    /**
     * Check if cURL supports SFTP protocol
     */
    private function checkCurlSFTPSupport() {
        if (!function_exists('curl_version')) {
            return false;
        }
        $curlInfo = curl_version();
        return in_array('sftp', $curlInfo['protocols']);
    }
    
    /**
     * Connect using cURL (no connection needed, just mark as connected)
     */
    private function connectCurl() {
        $this->sftp = 'curl'; // Mark as using curl
        return true;
    }
    
    /**
     * Connect using phpseclib v3
     */
    private function connectPhpseclib3() {
        $this->sftp = new \phpseclib3\Net\SFTP($this->config['host'], $this->config['port']);
        
        if (!$this->sftp->login($this->config['username'], $this->config['password'])) {
            throw new Exception("SFTP login failed with password");
        }
        
        return true;
    }
    
    /**
     * Connect using phpseclib v2
     */
    private function connectPhpseclib2() {
        $this->sftp = new \phpseclib\Net\SFTP($this->config['host'], $this->config['port']);
        
        if (!$this->sftp->login($this->config['username'], $this->config['password'])) {
            throw new Exception("SFTP login failed with password");
        }
        
        return true;
    }
    
    /**
     * Connect using native SSH2 extension
     */
    private function connectNative() {
        $this->connection = ssh2_connect($this->config['host'], $this->config['port']);
        
        if (!$this->connection) {
            throw new Exception("Failed to connect to SFTP server");
        }
        
        if (!ssh2_auth_password($this->connection, $this->config['username'], $this->config['password'])) {
            throw new Exception("SFTP authentication failed with password");
        }
        
        $this->sftp = ssh2_sftp($this->connection);
        if (!$this->sftp) {
            throw new Exception("Failed to initialize SFTP subsystem");
        }
        
        return true;
    }
    
    /**
     * List files in remote directory
     */
    public function listFiles($remotePath) {
        $files = [];
        
        if ($this->sftp === 'curl') {
            // Using cURL
            return $this->listFilesWithCurl($remotePath);
        }
        elseif (is_object($this->sftp)) {
            // Using phpseclib
            $fileList = $this->sftp->nlist($remotePath);
            
            foreach ($fileList as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                
                $fullPath = rtrim($remotePath, '/') . '/' . $file;
                $stat = $this->sftp->stat($fullPath);
                
                if (preg_match('/\.xml$/i', $file)) {
                    $files[] = [
                        'filename' => $file,
                        'path' => $fullPath,
                        'size' => $stat['size'] ?? 0,
                        'modified' => isset($stat['mtime']) ? date('Y-m-d H:i:s', $stat['mtime']) : null
                    ];
                }
            }
        } else {
            // Using native SSH2
            $handle = opendir("ssh2.sftp://{$this->sftp}" . $remotePath);
            
            while (false !== ($file = readdir($handle))) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                
                if (preg_match('/\.xml$/i', $file)) {
                    $fullPath = rtrim($remotePath, '/') . '/' . $file;
                    $sftpPath = "ssh2.sftp://{$this->sftp}" . $fullPath;
                    
                    $files[] = [
                        'filename' => $file,
                        'path' => $fullPath,
                        'size' => filesize($sftpPath),
                        'modified' => date('Y-m-d H:i:s', filemtime($sftpPath))
                    ];
                }
            }
            closedir($handle);
        }
        
        return $files;
    }
    
    /**
     * List files using cURL
     */
    private function listFilesWithCurl($remotePath) {
        // Properly encode path and construct URL
        $encodedPath = str_replace(' ', '%20', rtrim($remotePath, '/'));
        $url = sprintf(
            "sftp://%s:%d%s/",
            $this->config['host'],
            $this->config['port'],
            $encodedPath
        );
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERPWD, $this->config['username'] . ':' . $this->config['password']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_DIRLISTONLY, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $fileList = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception("SFTP cURL Error: $error");
        }
        
        $files = [];
        $fileNames = array_filter(explode("\n", trim($fileList)));
        
        foreach ($fileNames as $file) {
            if (preg_match('/\.xml$/i', $file)) {
                $fullPath = rtrim($remotePath, '/') . '/' . $file;
                $files[] = [
                    'filename' => $file,
                    'path' => $fullPath,
                    'size' => 0, // cURL DIRLISTONLY doesn't provide size
                    'modified' => null
                ];
            }
        }
        
        return $files;
    }
    
    /**
     * Download file from SFTP
     */
    public function downloadFile($remoteFile, $localFile) {
        if ($this->sftp === 'curl') {
            // Using cURL
            return $this->downloadFileWithCurl($remoteFile, $localFile);
        }
        elseif (is_object($this->sftp)) {
            // Using phpseclib
            return $this->sftp->get($remoteFile, $localFile);
        } else {
            // Using native SSH2
            return ssh2_scp_recv($this->connection, $remoteFile, $localFile);
        }
    }
    
    /**
     * Download file using cURL
     */
    private function downloadFileWithCurl($remoteFile, $localFile) {
        // Properly encode path and construct URL
        $encodedPath = str_replace(' ', '%20', $remoteFile);
        $url = sprintf(
            "sftp://%s:%d%s",
            $this->config['host'],
            $this->config['port'],
            $encodedPath
        );
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERPWD, $this->config['username'] . ':' . $this->config['password']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $content = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception("SFTP cURL Error: $error");
        }
        
        return file_put_contents($localFile, $content) !== false;
    }
    
    /**
     * Get file content as string
     */
    public function getFileContent($remoteFile) {
        if ($this->sftp === 'curl') {
            // Using cURL
            return $this->getFileContentWithCurl($remoteFile);
        }
        elseif (is_object($this->sftp)) {
            // Using phpseclib
            return $this->sftp->get($remoteFile);
        } else {
            // Using native SSH2
            $sftpPath = "ssh2.sftp://{$this->sftp}" . $remoteFile;
            return file_get_contents($sftpPath);
        }
    }
    
    /**
     * Get file content using cURL
     */
    private function getFileContentWithCurl($remoteFile) {
        // Properly encode path and construct URL
        $encodedPath = str_replace(' ', '%20', $remoteFile);
        $url = sprintf(
            "sftp://%s:%d%s",
            $this->config['host'],
            $this->config['port'],
            $encodedPath
        );
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERPWD, $this->config['username'] . ':' . $this->config['password']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $content = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception("SFTP cURL Error: $error");
        }
        
        return $content;
    }
    
    /**
     * Disconnect
     */
    public function disconnect() {
        if ($this->sftp === 'curl') {
            // cURL doesn't maintain persistent connections
            $this->sftp = null;
        }
        elseif (is_object($this->sftp)) {
            $this->sftp->disconnect();
        }
        $this->connection = null;
        $this->sftp = null;
    }
}

/**
 * Download and parse all files from SFTP
 */
function parseSFTPFiles($config, $parser, $downloadToLocal = false, $localDir = null) {
    $sftp = new SFTPConnection($config);
    $sftp->connect();
    
    $remotePath = $config['remote_path'];
    $files = $sftp->listFiles($remotePath);
    
    if (empty($files)) {
        throw new Exception("No XML files found on SFTP server");
    }
    
    $allOrders = [];
    $filesProcessed = 0;
    $fileErrors = [];
    
    foreach ($files as $fileInfo) {
        try {
            // Get file content directly
            $xmlContent = $sftp->getFileContent($fileInfo['path']);
            
            // Optionally save to local directory
            if ($downloadToLocal && $localDir) {
                if (!is_dir($localDir)) {
                    mkdir($localDir, 0755, true);
                }
                file_put_contents($localDir . '/' . $fileInfo['filename'], $xmlContent);
            }
            
            // Parse XML content
            $result = $parser->parseXML($xmlContent);
            
            // Merge orders from this file
            foreach ($result['orders'] as $order) {
                $order['sourceFile'] = $fileInfo['filename'];
                $order['sourceType'] = 'sftp';
                $allOrders[] = $order;
            }
            
            $filesProcessed++;
            
        } catch (Exception $e) {
            $fileErrors[] = [
                'file' => $fileInfo['filename'],
                'error' => $e->getMessage()
            ];
        }
    }
    
    $sftp->disconnect();
    
    // Calculate total line items
    $totalLineItems = 0;
    foreach ($allOrders as $order) {
        $totalLineItems += count($order['lineItems']);
    }
    
    return [
        'success' => true,
        'source' => 'sftp',
        'sftpHost' => $config['host'],
        'remotePath' => $remotePath,
        'filesProcessed' => $filesProcessed,
        'totalFiles' => count($files),
        'totalOrders' => count($allOrders),
        'totalLineItems' => $totalLineItems,
        'fileErrors' => $fileErrors,
        'orders' => $allOrders
    ];
}

/**
 * Download files from SFTP to local directory
 */
function downloadSFTPFiles($config, $localDir) {
    if (!is_dir($localDir)) {
        mkdir($localDir, 0755, true);
    }
    
    $sftp = new SFTPConnection($config);
    $sftp->connect();
    
    $files = $sftp->listFiles($config['remote_path']);
    
    if (empty($files)) {
        throw new Exception("No XML files found on SFTP server");
    }
    
    $downloaded = [];
    $errors = [];
    
    foreach ($files as $fileInfo) {
        $localPath = $localDir . '/' . $fileInfo['filename'];
        
        try {
            if ($sftp->downloadFile($fileInfo['path'], $localPath)) {
                $downloaded[] = [
                    'filename' => $fileInfo['filename'],
                    'localPath' => $localPath,
                    'size' => $fileInfo['size']
                ];
            }
        } catch (Exception $e) {
            $errors[] = [
                'file' => $fileInfo['filename'],
                'error' => $e->getMessage()
            ];
        }
    }
    
    $sftp->disconnect();
    
    return [
        'success' => true,
        'filesDownloaded' => count($downloaded),
        'totalFiles' => count($files),
        'localDirectory' => $localDir,
        'files' => $downloaded,
        'errors' => $errors
    ];
}

class OrderXMLParser {
    private $headers = [];
    private $orders = [];
    
    /**
     * Parse XML file and return structured data
     */
    public function parseFile($filePath) {
        if (!file_exists($filePath)) {
            throw new Exception("File not found: $filePath");
        }
        
        $xmlContent = file_get_contents($filePath);
        return $this->parseXML($xmlContent);
    }
    
    /**
     * Parse XML content string
     */
    public function parseXML($xmlContent) {
        // Load XML
        $xml = simplexml_load_string($xmlContent);
        
        if ($xml === false) {
            throw new Exception("Failed to parse XML");
        }
        
        // Register namespaces
        $xml->registerXPathNamespace('ss', 'urn:schemas-microsoft-com:office:spreadsheet');
        
        // Get all rows
        $rows = $xml->xpath('//ss:Row');
        
        if (empty($rows)) {
            throw new Exception("No rows found in XML");
        }
        
        // Parse header row (first row)
        $this->parseHeaders($rows[0]);
        
        // Parse data rows (skip first row)
        $this->parseDataRows(array_slice($rows, 1));
        
        return [
            'success' => true,
            'totalOrders' => count($this->orders),
            'totalLineItems' => $this->getTotalLineItems(),
            'orders' => array_values($this->orders)
        ];
    }
    
    /**
     * Parse header row
     */
    private function parseHeaders($headerRow) {
        $dataCells = $headerRow->xpath('.//ss:Data');
        if (empty($dataCells)) {
            throw new Exception("No header data found");
        }
        
        $headerString = (string)$dataCells[0];
        $this->headers = explode('|', $headerString);
    }
    
    /**
     * Parse all data rows and group by order
     */
    private function parseDataRows($dataRows) {
        $orderGroups = [];
        
        foreach ($dataRows as $row) {
            // Get all Data elements in this row
            $dataCells = $row->xpath('.//ss:Data');
            
            // Concatenate all cells in the row
            $fullRowData = '';
            foreach ($dataCells as $cell) {
                $fullRowData .= (string)$cell;
            }
            
            // Split by pipe delimiter
            $values = explode('|', $fullRowData);
            
            // Create associative array
            $lineItem = [];
            for ($i = 0; $i < count($this->headers) && $i < count($values); $i++) {
                $lineItem[$this->headers[$i]] = $values[$i];
            }
            
            // Group by DocumentID + PoNumber
            $orderId = $lineItem['DocumentID'] . '-' . $lineItem['PoNumber'];
            
            if (!isset($orderGroups[$orderId])) {
                $orderGroups[$orderId] = [
                    'documentId' => $lineItem['DocumentID'],
                    'companyCode' => $lineItem['CompanyCode'],
                    'customerNo' => $lineItem['CustomerNo'],
                    'poNumber' => $lineItem['PoNumber'],
                    'vendorNo' => $lineItem['VendorNo'],
                    'poDate' => $lineItem['PoDate'],
                    'shipDateOrder' => $lineItem['ShipDateOrder'],
                    'cancelDate' => $lineItem['CancelDate'],
                    'orderAmount' => floatval($lineItem['OrderAmount']),
                    'orderCases' => intval($lineItem['OrderCases']),
                    'orderWeight' => floatval($lineItem['OrderWeight']),
                    'methodOfPayment' => $lineItem['MethodOfPayment'],
                    'billTo' => [
                        'storeNumber' => $lineItem['BT_StoreNumber'],
                        'companyName1' => $lineItem['BT_CompanyName1'],
                        'companyName2' => $lineItem['BT_CompanyName2'],
                        'address1' => $lineItem['BT_Address1'],
                        'address2' => $lineItem['BT_Address2'],
                        'address3' => $lineItem['BT_Address3'],
                        'city' => $lineItem['BT_City'],
                        'state' => $lineItem['BT_State'],
                        'zip' => $lineItem['BT_Zip'],
                        'country' => $lineItem['BT_Country']
                    ],
                    'shipTo' => [
                        'storeNumber' => $lineItem['ST_StoreNumber'],
                        'companyName1' => $lineItem['ST_CompanyName1'],
                        'companyName2' => $lineItem['ST_CompanyName2'],
                        'address1' => $lineItem['ST_Address1'],
                        'address2' => $lineItem['ST_Address2'],
                        'address3' => $lineItem['ST_Address3'],
                        'city' => $lineItem['ST_City'],
                        'state' => $lineItem['ST_State'],
                        'zip' => $lineItem['ST_Zip'],
                        'country' => $lineItem['ST_Country']
                    ],
                    'shipFrom' => [
                        'companyName1' => $lineItem['SF_CompanyName1'],
                        'companyName2' => $lineItem['SF_CompanyName2'],
                        'address1' => $lineItem['SF_Address1'],
                        'city' => $lineItem['SF_City'],
                        'state' => $lineItem['SF_State'],
                        'zip' => $lineItem['SF_Zip'],
                        'country' => $lineItem['SF_Country']
                    ],
                    'terms' => [
                        'type' => $lineItem['TermsType'],
                        'basis' => $lineItem['TermsBasis'],
                        'dueDays' => $lineItem['DueDays'],
                        'netDays' => intval($lineItem['NetDays']),
                        'discountPercent' => intval($lineItem['DiscountPercent'])
                    ],
                    'lineItems' => []
                ];
            }
            
            // Add line item details
            $orderGroups[$orderId]['lineItems'][] = [
                'lineNo' => $lineItem['CustomerLine'],
                'vendorItemNo' => $lineItem['VendorItemNo'],
                'upcCode' => $lineItem['UPCCode'],
                'customerItem' => $lineItem['CustomerItem'],
                'quantityOrdered' => intval($lineItem['QuantityOrdered']),
                'unitMeasure' => $lineItem['UnitMeasure'],
                'unitPrice' => floatval($lineItem['UnitPrice']),
                'originalPrice' => floatval($lineItem['OriginalPrice']),
                'sellingPrice' => floatval($lineItem['SellingPrice']),
                'lineTotal' => floatval($lineItem['UnitPrice']) * intval($lineItem['QuantityOrdered']),
                'packSize' => $lineItem['PackSize'],
                'itemDesc' => $lineItem['ItemDesc'],
                'itemDesc2' => $lineItem['ItemDesc2'],
                'gtin' => $lineItem['GTIN'],
                'sku' => $lineItem['SKU'],
                'upcCaseCode' => $lineItem['UPCCaseCode'],
                'countryOfOrigin' => $lineItem['CountryOfOrigin'],
                'shipDateDetail' => $lineItem['ShipDateDetail']
            ];
        }
        
        $this->orders = $orderGroups;
    }
    
    /**
     * Get total line items across all orders
     */
    private function getTotalLineItems() {
        $total = 0;
        foreach ($this->orders as $order) {
            $total += count($order['lineItems']);
        }
        return $total;
    }
}

/**
 * Get all XML files in a directory
 */
function getAllXMLFiles($directory) {
    $xmlFiles = [];
    $files = scandir($directory);
    
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        
        $filePath = $directory . '/' . $file;
        
        if (is_file($filePath) && preg_match('/\.xml$/i', $file)) {
            $xmlFiles[] = [
                'filename' => $file,
                'path' => $filePath,
                'size' => filesize($filePath),
                'modified' => date('Y-m-d H:i:s', filemtime($filePath))
            ];
        }
    }
    
    return $xmlFiles;
}

/**
 * Parse all XML files in a directory
 */
function parseAllFiles($directory, $parser) {
    $xmlFiles = getAllXMLFiles($directory);
    
    if (empty($xmlFiles)) {
        throw new Exception("No XML files found in directory");
    }
    
    $allOrders = [];
    $filesProcessed = 0;
    $fileErrors = [];
    
    foreach ($xmlFiles as $fileInfo) {
        try {
            $result = $parser->parseFile($fileInfo['path']);
            
            // Merge orders from this file
            foreach ($result['orders'] as $order) {
                $order['sourceFile'] = $fileInfo['filename'];
                $allOrders[] = $order;
            }
            
            $filesProcessed++;
            
        } catch (Exception $e) {
            $fileErrors[] = [
                'file' => $fileInfo['filename'],
                'error' => $e->getMessage()
            ];
        }
    }
    
    // Calculate total line items
    $totalLineItems = 0;
    foreach ($allOrders as $order) {
        $totalLineItems += count($order['lineItems']);
    }
    
    return [
        'success' => true,
        'filesProcessed' => $filesProcessed,
        'totalFiles' => count($xmlFiles),
        'totalOrders' => count($allOrders),
        'totalLineItems' => $totalLineItems,
        'fileErrors' => $fileErrors,
        'orders' => $allOrders
    ];
}

// Main API Logic
try {
    $parser = new OrderXMLParser();
    $result = null;
    $directory = __DIR__ . '/orders'; // Base directory for file operations
    
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        
        // List files on SFTP server
        if (isset($_GET['action']) && $_GET['action'] === 'listSftp') {
            $sftp = new SFTPConnection($SFTP_CONFIG);
            $sftp->connect();
            
            $files = $sftp->listFiles($SFTP_CONFIG['remote_path']);
            $sftp->disconnect();
            
            $result = [
                'success' => true,
                'source' => 'sftp',
                'sftpHost' => $SFTP_CONFIG['host'],
                'remotePath' => $SFTP_CONFIG['remote_path'],
                'totalFiles' => count($files),
                'files' => $files
            ];
        }
        // Download files from SFTP to local
        elseif (isset($_GET['action']) && $_GET['action'] === 'downloadSftp') {
            $result = downloadSFTPFiles($SFTP_CONFIG, $directory);
        }
        // Download and parse files from SFTP
        elseif (isset($_GET['action']) && $_GET['action'] === 'parseSftp') {
            $downloadLocal = isset($_GET['download']) && $_GET['download'] === 'true';
            $result = parseSFTPFiles($SFTP_CONFIG, $parser, $downloadLocal, $directory);
        }
        // List all XML files locally
        elseif (isset($_GET['action']) && $_GET['action'] === 'list') {
            $xmlFiles = getAllXMLFiles($directory);
            
            $result = [
                'success' => true,
                'source' => 'local',
                'totalFiles' => count($xmlFiles),
                'files' => $xmlFiles
            ];
        }
        // Parse all XML files locally
        elseif (isset($_GET['action']) && $_GET['action'] === 'parseAll') {
            $result = parseAllFiles($directory, $parser);
        }
        // Parse single file
        else {
            $fileName = $_GET['file'] ?? 'wc-orders (1).xml';
            $filePath = $directory . '/' . basename($fileName); // Security: prevent directory traversal
            
            $result = $parser->parseFile($filePath);
        }
        
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Handle POST request with file upload or raw XML
        
        if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
            // File upload
            $result = $parser->parseFile($_FILES['file']['tmp_name']);
            
        } elseif (isset($_FILES['files']) && is_array($_FILES['files']['name'])) {
            // Multiple file upload
            $allOrders = [];
            $filesProcessed = 0;
            $fileErrors = [];
            
            foreach ($_FILES['files']['tmp_name'] as $index => $tmpName) {
                if ($_FILES['files']['error'][$index] === UPLOAD_ERR_OK) {
                    try {
                        $fileResult = $parser->parseFile($tmpName);
                        
                        foreach ($fileResult['orders'] as $order) {
                            $order['sourceFile'] = $_FILES['files']['name'][$index];
                            $allOrders[] = $order;
                        }
                        
                        $filesProcessed++;
                    } catch (Exception $e) {
                        $fileErrors[] = [
                            'file' => $_FILES['files']['name'][$index],
                            'error' => $e->getMessage()
                        ];
                    }
                }
            }
            
            $totalLineItems = 0;
            foreach ($allOrders as $order) {
                $totalLineItems += count($order['lineItems']);
            }
            
            $result = [
                'success' => true,
                'filesProcessed' => $filesProcessed,
                'totalOrders' => count($allOrders),
                'totalLineItems' => $totalLineItems,
                'fileErrors' => $fileErrors,
                'orders' => $allOrders
            ];
            
        } else {
            // Raw XML content in body
            $xmlContent = file_get_contents('php://input');
            
            if (empty($xmlContent)) {
                throw new Exception("No XML content provided");
            }
            
            $result = $parser->parseXML($xmlContent);
        }
    } else {
        throw new Exception("Method not allowed. Use GET or POST");
    }
    
    // Return JSON response
    http_response_code(200);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    
} catch (Exception $e) {
    // Error response
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}

