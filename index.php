<?php
/**
 * WooCommerce Orders XML Parser API - COMPLETE DUAL FORMAT SUPPORT
 * Supports both Excel XML and Lingo XML formats
 * 
 * Version: 3.0 - FIXED ARCHIVE LOGIC
 * Last Updated: January 2026
 * 
 * ENDPOINTS:
 * - GET  ?action=parseSftp   → Parse XML files (does NOT archive)
 * - POST action=archiveFiles → Archive specific files after Salesforce processing
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// SFTP Configuration
$SFTP_CONFIG = [
    'host' => $_SERVER['HTTP_SFTP_HOST'] ?? getenv('SFTP_HOST'),
    'port' => $_SERVER['HTTP_SFTP_PORT'] ?? getenv('SFTP_PORT') ?: 22,
    'username' => $_SERVER['HTTP_USERNAME'] ?? getenv('SFTP_USERNAME'),
    'password' => $_SERVER['HTTP_PASSWORD'] ?? getenv('SFTP_PASSWORD'),
    'remote_path' => getenv('SFTP_REMOTE_PATH') ?: '/EDI850_Orders'
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
    
    public function connect() {
        error_log("🔌 SFTP Connection Method Detection:");
        
        if (function_exists('curl_init') && $this->checkCurlSFTPSupport()) {
            $this->sftp = 'curl';
            error_log("   ✅ Using cURL SFTP");
            return true;
        }
        elseif (function_exists('ssh2_connect')) {
            error_log("   ✅ Using Native SSH2");
            return $this->connectNative();
        } else {
            error_log("   ❌ No SFTP library available");
            throw new Exception("No SFTP library available");
        }
    }
    
    private function checkCurlSFTPSupport() {
        if (!function_exists('curl_version')) return false;
        $curlInfo = curl_version();
        return in_array('sftp', $curlInfo['protocols']);
    }
    
    private function connectNative() {
        $this->connection = ssh2_connect($this->config['host'], $this->config['port']);
        if (!$this->connection) throw new Exception("Failed to connect to SFTP server");
        if (!ssh2_auth_password($this->connection, $this->config['username'], $this->config['password'])) {
            throw new Exception("SFTP authentication failed");
        }
        $this->sftp = ssh2_sftp($this->connection);
        if (!$this->sftp) throw new Exception("Failed to initialize SFTP subsystem");
        return true;
    }
    
    public function listFiles($remotePath) {
        $files = [];
        
        if ($this->sftp === 'curl') {
            return $this->listFilesWithCurl($remotePath);
        } else {
            $handle = @opendir("ssh2.sftp://{$this->sftp}" . $remotePath);
            if (!$handle) {
                throw new Exception("Failed to open directory: $remotePath");
            }
            
            while (false !== ($file = readdir($handle))) {
                if ($file === '.' || $file === '..') continue;
                if (preg_match('/\.xml$/i', $file)) {
                    $fullPath = rtrim($remotePath, '/') . '/' . $file;
                    $sftpPath = "ssh2.sftp://{$this->sftp}" . $fullPath;
                    $files[] = [
                        'filename' => $file,
                        'path' => $fullPath,
                        'size' => @filesize($sftpPath) ?: 0,
                        'modified' => @filemtime($sftpPath) ? date('Y-m-d H:i:s', filemtime($sftpPath)) : null
                    ];
                }
            }
            closedir($handle);
        }
        return $files;
    }
    
    private function listFilesWithCurl($remotePath) {
        $encodedPath = str_replace(' ', '%20', rtrim($remotePath, '/'));
        $url = sprintf("sftp://%s:%d%s/", $this->config['host'], $this->config['port'], $encodedPath);
        
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
        
        if ($error) throw new Exception("SFTP cURL Error: $error");
        
        $files = [];
        $fileNames = array_filter(explode("\n", trim($fileList)));
        foreach ($fileNames as $file) {
            $file = trim($file);
            if (preg_match('/\.xml$/i', $file)) {
                $files[] = [
                    'filename' => $file,
                    'path' => rtrim($remotePath, '/') . '/' . $file,
                    'size' => 0,
                    'modified' => null
                ];
            }
        }
        return $files;
    }
    
    public function getFileContent($remoteFile) {
        if ($this->sftp === 'curl') {
            return $this->getFileContentWithCurl($remoteFile);
        } else {
            $sftpPath = "ssh2.sftp://{$this->sftp}" . $remoteFile;
            $content = @file_get_contents($sftpPath);
            if ($content === false) {
                throw new Exception("Failed to read file: $remoteFile");
            }
            return $content;
        }
    }
    
    private function getFileContentWithCurl($remoteFile) {
        // Properly encode the path for URL, but preserve the file structure
        $pathParts = explode('/', $remoteFile);
        $encodedParts = array_map('rawurlencode', $pathParts);
        $encodedPath = implode('/', $encodedParts);
        
        $url = sprintf("sftp://%s:%d%s", $this->config['host'], $this->config['port'], $encodedPath);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERPWD, $this->config['username'] . ':' . $this->config['password']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $content = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) throw new Exception("SFTP cURL Error: $error");
        return $content;
    }

    public function uploadFile($localFile, $remoteFile) {
        if ($this->sftp === 'curl') {
            return $this->uploadFileWithCurl($localFile, $remoteFile);
        } else {
            $stream = @fopen("ssh2.sftp://{$this->sftp}" . $remoteFile, 'w');
            if (!$stream) {
                throw new Exception("Failed to open remote file for writing: $remoteFile");
            }
            
            $data = file_get_contents($localFile);
            if (fwrite($stream, $data) === false) {
                fclose($stream);
                throw new Exception("Failed to write to remote file: $remoteFile");
            }
            
            fclose($stream);
            return true;
        }
    }
    
    private function uploadFileWithCurl($localFile, $remoteFile) {
        // Properly encode the path for URL
        $pathParts = explode('/', $remoteFile);
        $encodedParts = array_map('rawurlencode', $pathParts);
        $encodedPath = implode('/', $encodedParts);
        
        $url = sprintf("sftp://%s:%d%s", $this->config['host'], $this->config['port'], $encodedPath);
        
        $fp = fopen($localFile, 'r');
        if (!$fp) {
            throw new Exception("Failed to open local file: $localFile");
        }
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERPWD, $this->config['username'] . ':' . $this->config['password']);
        curl_setopt($ch, CURLOPT_UPLOAD, true);
        curl_setopt($ch, CURLOPT_INFILE, $fp);
        curl_setopt($ch, CURLOPT_INFILESIZE, filesize($localFile));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $result = curl_exec($ch);
        $error = curl_error($ch);
        
        fclose($fp);
        curl_close($ch);
        
        if ($error) {
            throw new Exception("SFTP cURL Upload Error: $error");
        }
        
        return true;
    }

    public function deleteFile($remoteFile) {
        if ($this->sftp === 'curl') {
            return $this->deleteFileWithCurl($remoteFile);
        } else {
            // Use SSH2 SFTP - handles special characters better
            $result = @ssh2_sftp_unlink($this->sftp, $remoteFile);
            if (!$result) {
                // If SSH2 fails, try using ssh2_exec for more robust delete
                try {
                    $command = 'rm -f ' . escapeshellarg($remoteFile);
                    $stream = @ssh2_exec($this->connection, $command);
                    if ($stream) {
                        stream_set_blocking($stream, true);
                        $output = stream_get_contents($stream);
                        fclose($stream);
                        return true;
                    }
                } catch (Exception $e) {
                    throw new Exception("Failed to delete file using SSH2: $remoteFile - " . $e->getMessage());
                }
                throw new Exception("Failed to delete file: $remoteFile");
            }
            return true;
        }
    }
    
    private function deleteFileWithCurl($remoteFile) {
        error_log("   🗑️  DELETE OPERATION (cURL method)");
        error_log("      File path: $remoteFile");
        
        // CRITICAL: For SFTP delete to work, we need to use POSTQUOTE, not QUOTE
        // QUOTE executes BEFORE the main request, POSTQUOTE executes AFTER
        $url = sprintf("sftp://%s:%d/", $this->config['host'], $this->config['port']);
        
        // Escape the file path properly for the shell command
        $escapedPath = str_replace('"', '\\"', $remoteFile);
        $deleteCommand = 'rm "' . $escapedPath . '"';
        
        error_log("      Command: $deleteCommand");
        error_log("      URL: $url");
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERPWD, $this->config['username'] . ':' . $this->config['password']);
        
        // CRITICAL FIX: Use POSTQUOTE instead of QUOTE
        // POSTQUOTE executes the command AFTER the transfer completes
        curl_setopt($ch, CURLOPT_POSTQUOTE, array($deleteCommand));
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_NOBODY, true); // Don't download file content
        
        $result = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        
        curl_close($ch);
        
        if ($error) {
            error_log("      ❌ cURL Error: $error (Code: $httpCode)");
            throw new Exception("SFTP cURL Delete Error: $error (HTTP Code: $httpCode)");
        }
        
        error_log("      ✅ Delete command executed");
        error_log("      Response code: $httpCode");
        
        return true;
    }
    
    public function disconnect() {
        if ($this->sftp === 'curl') {
            $this->sftp = null;
        }
        $this->connection = null;
        $this->sftp = null;
    }
}

/**
 * COMPLETE DUAL FORMAT ORDER PARSER
 */
class OrderXMLParser {
    private $headers = [];
    private $orders = [];
    
    public function parseFile($filePath) {
        if (!file_exists($filePath)) {
            throw new Exception("File not found: $filePath");
        }
        $xmlContent = file_get_contents($filePath);
        return $this->parseXML($xmlContent);
    }
    
    public function parseXML($xmlContent) {
        // Detect format based on root element
        if (strpos($xmlContent, '<Workbook') !== false) {
            return $this->parseExcelXML($xmlContent);
        } 
        elseif (strpos($xmlContent, '<File>') !== false || strpos($xmlContent, '<Document>') !== false) {
            return $this->parseLingoXML($xmlContent);
        } 
        else {
            throw new Exception("Unsupported XML format. Expected Excel XML or Lingo XML.");
        }
    }
    
    private function parseExcelXML($xmlContent) {
        $xml = simplexml_load_string($xmlContent);
        if ($xml === false) throw new Exception("Failed to parse Excel XML");
        
        $xml->registerXPathNamespace('ss', 'urn:schemas-microsoft-com:office:spreadsheet');
        $rows = $xml->xpath('//ss:Row');
        if (empty($rows)) throw new Exception("No rows found in Excel XML");
        
        $this->parseExcelHeaders($rows[0]);
        $this->parseExcelDataRows(array_slice($rows, 1));
        
        return [
            'success' => true,
            'format' => 'Excel XML',
            'totalOrders' => count($this->orders),
            'totalLineItems' => $this->getTotalLineItems(),
            'orders' => array_values($this->orders)
        ];
    }
    
    private function parseLingoXML($xmlContent) {
        $xml = simplexml_load_string($xmlContent);
        if ($xml === false) throw new Exception("Failed to parse Lingo XML");
        
        $orders = [];
        
        foreach ($xml->Document as $document) {
            $order = [
                'documentId' => (string)$document->InternalDocumentNumber ?: (string)$document->InternalOrderNumber,
                'companyCode' => (string)$document->CompanyCode,
                'customerNo' => (string)$document->CustomerNumber,
                'poNumber' => (string)$document->PurchaseOrderNumber,
                'vendorNo' => '',
                'poDate' => $this->formatLingoDate((string)$document->PurchaseOrderDate),
                'shipDateOrder' => '',
                'cancelDate' => '',
                'orderAmount' => 0,
                'orderCases' => 0,
                'orderWeight' => 0,
                'methodOfPayment' => '',
                'billTo' => [],
                'shipTo' => [],
                'shipFrom' => [],
                'terms' => [],
                'lineItems' => []
            ];
            
            // Parse Header
            if (isset($document->Header)) {
                $header = $document->Header;
                $order['poDate'] = $this->formatLingoDate((string)$header->PurchaseOrderDate) ?: $order['poDate'];
                $order['vendorNo'] = (string)$header->VendorNumber;
                
                if (isset($header->OrderTotals)) {
                    $order['orderAmount'] = (float)$header->OrderTotals->TotalAmount;
                    if (isset($header->OrderTotals->Weight->UOM->Quantity)) {
                        $order['orderWeight'] = (float)$header->OrderTotals->Weight->UOM->Quantity;
                    }
                    if (isset($header->OrderTotals->Cartons->UOM->Quantity)) {
                        $order['orderCases'] = (int)$header->OrderTotals->Cartons->UOM->Quantity;
                    }
                }
                
                foreach ($header->DateLoop as $dateLoop) {
                    $qualifier = (string)$dateLoop->DateQualifier;
                    $date = $this->formatLingoDate((string)$dateLoop->Date);
                    
                    switch ($qualifier) {
                        case '004':
                            $order['poDate'] = $date;
                            break;
                        case '010':
                        case '011':
                            $order['shipDateOrder'] = $date;
                            break;
                        case '001':
                            $order['cancelDate'] = $date;
                            break;
                    }
                }
            }
            
            // Parse Addresses
            foreach ($document->n as $address) {
                $code = (string)$address->BillAndShipToCode;
                $addressData = [
                    'storeNumber' => (string)$address->DUNSOrLocationNumber,
                    'companyName1' => (string)$address->CompanyName,
                    'companyName2' => '',
                    'address1' => (string)$address->Address,
                    'address2' => '',
                    'address3' => '',
                    'city' => (string)$address->City,
                    'state' => (string)$address->State,
                    'zip' => (string)$address->Zip,
                    'country' => (string)$address->Country
                ];
                
                switch ($code) {
                    case 'BT':
                        $order['billTo'] = $addressData;
                        break;
                    case 'ST':
                        $order['shipTo'] = $addressData;
                        break;
                    case 'SF':
                        $order['shipFrom'] = $addressData;
                        break;
                }
            }
            
            // Parse Line Items
            $lineNo = 1;
            foreach ($document->Detail as $detail) {
                $lineItem = $detail->DetailLine;
                
                $vendorItemNo = '';
                $upcCode = '';
                foreach ($lineItem->ItemIds as $itemId) {
                    $qualifier = (string)$itemId->IdQualifier;
                    $id = (string)$itemId->Id;
                    if ($qualifier === 'UP') {
                        $vendorItemNo = $id;
                        $upcCode = $id;
                    }
                }
                
                $quantity = 0;
                $uom = '';
                foreach ($lineItem->Quantities as $qty) {
                    $qualifier = (string)$qty->QtyQualifier;
                    if ($qualifier === '01' || $qualifier === '38') {
                        $quantity = (int)$qty->Qty;
                        $uom = (string)$qty->QtyUOM;
                        break;
                    }
                }
                
                $unitPrice = 0;
                if (isset($lineItem->PriceCost)) {
                    $unitPrice = (float)$lineItem->PriceCost->PriceOrCost;
                }
                
                $item = [
                    'lineNo' => str_pad((string)$lineItem->CustomerLineNumber ?: $lineNo, 4, '0', STR_PAD_LEFT),
                    'vendorItemNo' => $vendorItemNo,
                    'upcCode' => $upcCode,
                    'customerItem' => '',
                    'quantityOrdered' => $quantity,
                    'unitMeasure' => $uom,
                    'unitPrice' => $unitPrice,
                    'originalPrice' => $unitPrice,
                    'sellingPrice' => $unitPrice,
                    'lineTotal' => $unitPrice * $quantity,
                    'packSize' => (string)$lineItem->PackSize,
                    'itemDesc' => (string)$lineItem->ItemDescription,
                    'itemDesc2' => '',
                    'gtin' => '',
                    'sku' => '',
                    'upcCaseCode' => '',
                    'countryOfOrigin' => '',
                    'shipDateDetail' => ''
                ];
                
                $order['lineItems'][] = $item;
                $lineNo++;
            }
            
            // Parse Terms
            if (isset($document->Term)) {
                $order['terms'] = [
                    'termType' => (string)$document->Term->TermsType,
                    'basis' => (string)$document->Term->TermsBasis,
                    'dueDays' => '',
                    'netDays' => (int)$document->Term->NetDueDays,
                    'discountPercent' => (float)$document->Term->DiscountPercent
                ];
            }
            
            $orders[] = $order;
        }
        
        $totalLineItems = 0;
        foreach ($orders as $order) {
            $totalLineItems += count($order['lineItems']);
        }
        
        return [
            'success' => true,
            'format' => 'Lingo XML',
            'totalOrders' => count($orders),
            'totalLineItems' => $totalLineItems,
            'orders' => $orders
        ];
    }
    
    private function formatLingoDate($dateStr) {
        if (empty($dateStr)) return '';
        if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $dateStr)) {
            return $dateStr;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dateStr, $matches)) {
            return intval($matches[2]) . '/' . intval($matches[3]) . '/' . $matches[1];
        }
        return $dateStr;
    }
    
    private function parseExcelHeaders($headerRow) {
        $dataCells = $headerRow->xpath('.//ss:Data');
        if (empty($dataCells)) throw new Exception("No header data found");
        $headerString = (string)$dataCells[0];
        $this->headers = explode('|', $headerString);
    }
    
    private function parseExcelDataRows($dataRows) {
        $orderGroups = [];
        
        foreach ($dataRows as $row) {
            $dataCells = $row->xpath('.//ss:Data');
            $fullRowData = '';
            foreach ($dataCells as $cell) {
                $fullRowData .= (string)$cell;
            }
            $values = explode('|', $fullRowData);
            
            $lineItem = [];
            for ($i = 0; $i < count($this->headers) && $i < count($values); $i++) {
                $lineItem[$this->headers[$i]] = $values[$i];
            }
            
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
                        'termType' => $lineItem['TermsType'],
                        'basis' => $lineItem['TermsBasis'],
                        'dueDays' => $lineItem['DueDays'],
                        'netDays' => intval($lineItem['NetDays']),
                        'discountPercent' => intval($lineItem['DiscountPercent'])
                    ],
                    'lineItems' => []
                ];
            }
            
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
    
    private function getTotalLineItems() {
        $total = 0;
        foreach ($this->orders as $order) {
            $total += count($order['lineItems']);
        }
        return $total;
    }
}

/**
 * Parse files from SFTP WITHOUT ARCHIVING
 * Returns file names for Salesforce to archive later
 */
function parseSFTPFilesWithoutArchive($config, $parser) {
    error_log("=== EDI 850 ORDER PARSING STARTED (NO ARCHIVE) ===");
    
    $sftp = new SFTPConnection($config);
    $sftp->connect();
    
    error_log("SFTP Connected: " . $config['host']);
    
    $files = $sftp->listFiles($config['remote_path']);
    error_log("Files found: " . count($files));
    
    if (empty($files)) {
        $sftp->disconnect();
        return [
            'success' => true,
            'source' => 'sftp',
            'formats' => [],
            'sftpHost' => $config['host'],
            'remotePath' => $config['remote_path'],
            'filesProcessed' => 0,
            'totalFiles' => 0,
            'totalOrders' => 0,
            'totalLineItems' => 0,
            'processedFileNames' => [],
            'fileErrors' => [],
            'orders' => []
        ];
    }
    
    $allOrders = [];
    $filesProcessed = 0;
    $fileErrors = [];
    $formats = [];
    $processedFileNames = [];
    
    foreach ($files as $fileInfo) {
        $fileName = $fileInfo['filename'];
        error_log("📄 Processing file: $fileName");
        
        try {
            // Read file content
            $xmlContent = $sftp->getFileContent($fileInfo['path']);
            error_log("  ✓ File read: " . strlen($xmlContent) . " bytes");
            
            // Parse XML
            $result = $parser->parseXML($xmlContent);
            error_log("  ✓ Parsed: " . $result['totalOrders'] . " orders, " . $result['totalLineItems'] . " line items");
            
            // Validate that we got orders
            if (!isset($result['orders']) || !is_array($result['orders'])) {
                throw new Exception("Invalid parse result - no orders array");
            }
            
            if ($result['totalOrders'] === 0) {
                error_log("  ⚠ WARNING: File parsed but contains 0 orders");
            }
            
            // Add format
            $formats[] = $result['format'];
            
            // Add orders to collection
            foreach ($result['orders'] as $order) {
                $order['sourceFile'] = $fileName;
                $order['sourceType'] = 'sftp';
                $order['sourceFormat'] = $result['format'];
                $allOrders[] = $order;
            }
            
            // Mark as successfully parsed (for archiving later)
            $processedFileNames[] = $fileName;
            $filesProcessed++;
            
            error_log("  ✅ File parsing complete: $fileName");
            
        } catch (Exception $e) {
            $fileErrors[] = [
                'file' => $fileName,
                'error' => $e->getMessage()
            ];
            error_log("  ❌ Parsing FAILED for $fileName: " . $e->getMessage());
        }
    }
    
    $sftp->disconnect();
    error_log("SFTP Disconnected");
    
    $totalLineItems = 0;
    foreach ($allOrders as $order) {
        $totalLineItems += count($order['lineItems']);
    }
    
    error_log("=== PARSING COMPLETE (FILES NOT ARCHIVED) ===");
    error_log("Successfully parsed files: $filesProcessed");
    error_log("Failed files: " . count($fileErrors));
    error_log("Total orders extracted: " . count($allOrders));
    error_log("Total line items: $totalLineItems");
    error_log("Files to be archived by Salesforce: " . implode(', ', $processedFileNames));
    error_log("=============================================");
    
    return [
        'success' => true,
        'source' => 'sftp',
        'formats' => array_unique($formats),
        'sftpHost' => $config['host'],
        'remotePath' => $config['remote_path'],
        'filesProcessed' => $filesProcessed,
        'totalFiles' => count($files),
        'totalOrders' => count($allOrders),
        'totalLineItems' => $totalLineItems,
        'processedFileNames' => $processedFileNames, // CRITICAL: Return file names
        'fileErrors' => $fileErrors,
        'orders' => $allOrders
    ];
}

/**
 * Archive specific processed files
 * Called by Salesforce AFTER successful order processing
 */
function archiveSpecificFiles($config, $fileNames) {
    error_log("=== ARCHIVING PROCESSED FILES ===");
    error_log("Files to archive: " . implode(', ', $fileNames));
    
    try {
        $sftp = new SFTPConnection($config);
        $sftp->connect();
        error_log("✅ SFTP Connected for archiving");
    } catch (Exception $e) {
        error_log("❌ SFTP Connection failed: " . $e->getMessage());
        return [
            'success' => false,
            'archivedCount' => 0,
            'errorCount' => count($fileNames),
            'archivedFiles' => [],
            'errors' => ['SFTP Connection failed: ' . $e->getMessage()]
        ];
    }
    
    $sourcePath = $config['remote_path'];
    $archivePath = '/Archived';
    
    $archived = [];
    $errors = [];
    
    foreach ($fileNames as $fileName) {
        // Trim and clean file name
        $fileName = trim($fileName);
        error_log("📦 Archiving: $fileName");
        
        try {
            $sourceFile = rtrim($sourcePath, '/') . '/' . $fileName;
            $destFile = rtrim($archivePath, '/') . '/' . $fileName;
            
            error_log("  Source: $sourceFile");
            error_log("  Destination: $destFile");
            
            // Read file content
            $content = $sftp->getFileContent($sourceFile);
            error_log("  1. Read file: " . strlen($content) . " bytes");
            
            // Create temp file
            $tempFile = sys_get_temp_dir() . '/' . basename($fileName);
            $bytesWritten = file_put_contents($tempFile, $content);
            
            if ($bytesWritten === false || $bytesWritten === 0) {
                throw new Exception("Failed to create temp file");
            }
            error_log("  2. Created temp file: $tempFile ($bytesWritten bytes)");
            
            // Upload to archived folder
            $sftp->uploadFile($tempFile, $destFile);
            error_log("  3. Uploaded to: $destFile");
            
            // Verify upload by checking if we can read it back
            try {
                $verifyContent = $sftp->getFileContent($destFile);
                if (strlen($verifyContent) !== strlen($content)) {
                    throw new Exception("Upload verification failed - size mismatch");
                }
                error_log("  4. Upload verified (size matches)");
            } catch (Exception $e) {
                throw new Exception("Upload verification failed: " . $e->getMessage());
            }
            
            // NOW delete from source (only after verified upload)
            try {
                $sftp->deleteFile($sourceFile);
                error_log("  5. Deleted from source: $sourceFile");
            } catch (Exception $deleteEx) {
                // Log but don't fail - file is already in archive
                error_log("  ⚠ WARNING: Could not delete source file: " . $deleteEx->getMessage());
                error_log("  ℹ File successfully archived but remains in source");
            }
            
            // Clean up temp file
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
            
            $archived[] = $fileName;
            error_log("  ✅ SUCCESSFULLY ARCHIVED: $fileName");
            
        } catch (Exception $e) {
            $errorDetail = [
                'file' => $fileName,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ];
            $errors[] = "$fileName: " . $e->getMessage();
            error_log("  ❌ ARCHIVE FAILED for $fileName: " . $e->getMessage());
            error_log("  Error Line: " . $e->getLine());
            error_log("  Stack Trace: " . $e->getTraceAsString());
            
            // Clean up temp file on error
            if (isset($tempFile) && file_exists($tempFile)) {
                unlink($tempFile);
            }
            
            // CRITICAL: If archive fails, the file stays in source folder for retry
            error_log("  ⚠ File remains in source folder for retry");
        }
    }
    
    $sftp->disconnect();
    error_log("SFTP Disconnected");
    
    error_log("=== ARCHIVE COMPLETE ===");
    error_log("Successfully archived: " . count($archived));
    error_log("Archive errors: " . count($errors));
    error_log("========================");
    
    return [
        'success' => count($errors) === 0,
        'archivedCount' => count($archived),
        'errorCount' => count($errors),
        'archivedFiles' => $archived,
        'errors' => $errors
    ];
}

// ========================================
// MAIN API LOGIC
// ========================================

try {
    $parser = new OrderXMLParser();
    $result = null;
    
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (isset($_GET['action']) && $_GET['action'] === 'parseSftp') {
            // Parse files WITHOUT archiving
            $result = parseSFTPFilesWithoutArchive($SFTP_CONFIG, $parser);
        } else {
            throw new Exception("Invalid GET action. Use ?action=parseSftp");
        }
    } 
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            throw new Exception("Invalid JSON in POST body");
        }
        
        if (isset($input['action']) && $input['action'] === 'archiveFiles') {
            // Archive specific files
            if (!isset($input['files']) || !is_array($input['files'])) {
                throw new Exception("Missing 'files' array in request body");
            }
            
            if (empty($input['files'])) {
                throw new Exception("Empty 'files' array - nothing to archive");
            }
            
            $result = archiveSpecificFiles($SFTP_CONFIG, $input['files']);
        } else {
            throw new Exception("Invalid POST action. Use action=archiveFiles with files array");
        }
    } 
    else {
        throw new Exception("Method not allowed. Use GET or POST.");
    }
    
    http_response_code(200);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    
} catch (Exception $e) {
    error_log("API ERROR: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
?>
