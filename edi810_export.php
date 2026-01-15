<?php
/**
 * EDI 810 Invoice Export - Lingo XML Format
 * Receives JSON from Salesforce and uploads XML to SFTP server
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Configuration
$COMPANY_CODE = 'SIL02';
$LOG_FILE = __DIR__ . '/edi810_export.log';
$LOCAL_OUTPUT_DIR = __DIR__ . '/EDI810_Invoices';

// SFTP Configuration
$SFTP_CONFIG = [
    'host' => getenv('SFTP_HOST') ?: 'virginia.sftptogo.com',
    'port' => getenv('SFTP_PORT') ?: 22,
    'username' => getenv('SFTP_USERNAME') ?: 'def00441166779c394b1ebf405d60a',
    'password' => getenv('SFTP_PASSWORD') ?: '7Ivut003QohHdnzxzsPzKbkTbGGWHj',
    'remote_path' => '/EDI810_Invoices'
];

/**
 * Log message
 */
function logMessage($message) {
    global $LOG_FILE;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($LOG_FILE, "[$timestamp] $message\n", FILE_APPEND);
}

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
     * Connect to SFTP server
     */
    public function connect() {
        // Try cURL first (most compatible)
        if (function_exists('curl_init') && $this->checkCurlSFTPSupport()) {
            $this->sftp = 'curl';
            return true;
        }
        // Try native SSH2 extension
        elseif (function_exists('ssh2_connect')) {
            return $this->connectNative();
        } else {
            throw new Exception("No SFTP library available");
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
     * Connect using native SSH2 extension
     */
    private function connectNative() {
        $this->connection = ssh2_connect($this->config['host'], $this->config['port']);
        
        if (!$this->connection) {
            throw new Exception("Failed to connect to SFTP server");
        }
        
        if (!ssh2_auth_password($this->connection, $this->config['username'], $this->config['password'])) {
            throw new Exception("SFTP authentication failed");
        }
        
        $this->sftp = ssh2_sftp($this->connection);
        if (!$this->sftp) {
            throw new Exception("Failed to initialize SFTP subsystem");
        }
        
        return true;
    }
    
    /**
     * Upload file to SFTP server
     */
    public function uploadFile($localFile, $remoteFile) {
        if ($this->sftp === 'curl') {
            return $this->uploadFileWithCurl($localFile, $remoteFile);
        } else {
            return $this->uploadFileNative($localFile, $remoteFile);
        }
    }
    
    /**
     * Upload file using cURL
     */
    private function uploadFileWithCurl($localFile, $remoteFile) {
        $encodedPath = str_replace(' ', '%20', $remoteFile);
        $url = sprintf(
            "sftp://%s:%d%s",
            $this->config['host'],
            $this->config['port'],
            $encodedPath
        );
        
        $fp = fopen($localFile, 'r');
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERPWD, $this->config['username'] . ':' . $this->config['password']);
        curl_setopt($ch, CURLOPT_UPLOAD, 1);
        curl_setopt($ch, CURLOPT_INFILE, $fp);
        curl_setopt($ch, CURLOPT_INFILESIZE, filesize($localFile));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $result = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        
        fclose($fp);
        curl_close($ch);
        
        if ($error) {
            throw new Exception("cURL SFTP upload error: $error");
        }
        
        return true;
    }
    
    /**
     * Upload file using native SSH2
     */
    private function uploadFileNative($localFile, $remoteFile) {
        return ssh2_scp_send($this->connection, $localFile, $remoteFile, 0644);
    }
    
    /**
     * Create remote directory
     */
    public function createDirectory($remotePath) {
        if ($this->sftp === 'curl') {
            // cURL doesn't need explicit directory creation
            return true;
        } else {
            $sftpPath = "ssh2.sftp://{$this->sftp}" . $remotePath;
            if (!is_dir($sftpPath)) {
                return ssh2_sftp_mkdir($this->sftp, $remotePath, 0755, true);
            }
            return true;
        }
    }
    
    /**
     * Disconnect
     */
    public function disconnect() {
        if ($this->sftp === 'curl') {
            $this->sftp = null;
        }
        $this->connection = null;
        $this->sftp = null;
    }
}

/**
 * Convert JSON invoices to Lingo XML format
 */
function convertToLingoXML($invoices, $companyCode) {
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<File>' . "\n";
    
    foreach ($invoices as $invoice) {
        $xml .= '  <Document>' . "\n";
        
        // Document Metadata
        $xml .= '    <CompanyCode>' . xmlEscape($companyCode) . '</CompanyCode>' . "\n";
        $xml .= '    <CustomerNumber>' . xmlEscape($invoice['customerNo'] ?? '') . '</CustomerNumber>' . "\n";
        $xml .= '    <Direction>Outbound</Direction>' . "\n";
        $xml .= '    <DocumentType>810</DocumentType>' . "\n";
        $xml .= '    <Footprint>INV</Footprint>' . "\n";
        $xml .= '    <Version>3.0</Version>' . "\n";
        $xml .= '    <PurchaseOrderNumber>' . xmlEscape($invoice['poNumber'] ?? '') . '</PurchaseOrderNumber>' . "\n";
        $xml .= '    <InvoiceNumber>' . xmlEscape($invoice['invoiceNumber'] ?? '') . '</InvoiceNumber>' . "\n";
        
        // Header Section
        $xml .= '    <Header>' . "\n";
        $xml .= '      <InvoiceDate>' . formatDateForXML($invoice['invoiceDate'] ?? '') . '</InvoiceDate>' . "\n";
        
        // Date Loops
        $xml .= '      <DateLoop>' . "\n";
        $xml .= '        <DateQualifier Desc="InvoiceDate">003</DateQualifier>' . "\n";
        $xml .= '        <Date>' . formatDateForXML($invoice['invoiceDate'] ?? '') . '</Date>' . "\n";
        $xml .= '      </DateLoop>' . "\n";
        
        if (!empty($invoice['dueDate'])) {
            $xml .= '      <DateLoop>' . "\n";
            $xml .= '        <DateQualifier Desc="DueDate">002</DateQualifier>' . "\n";
            $xml .= '        <Date>' . formatDateForXML($invoice['dueDate']) . '</Date>' . "\n";
            $xml .= '      </DateLoop>' . "\n";
        }
        
        // Calculate total quantity
        $totalQuantity = 0;
        foreach ($invoice['lineItems'] as $line) {
            $totalQuantity += ($line['quantity'] ?? 0);
        }
        
        // Invoice Totals
        $xml .= '      <InvoiceTotals>' . "\n";
        $xml .= '        <InvoiceTotalAmount>' . number_format($invoice['totalAmount'] ?? 0, 1, '.', '') . '</InvoiceTotalAmount>' . "\n";
        $xml .= '        <MerchandiseAmount>' . number_format(($invoice['totalAmount'] ?? 0) + ($invoice['taxAmount'] ?? 0), 1, '.', '') . '</MerchandiseAmount>' . "\n";
        $xml .= '        <AmountLessTermsDiscount>' . number_format($invoice['totalAmount'] ?? 0, 1, '.', '') . '</AmountLessTermsDiscount>' . "\n";
        $xml .= '        <Weight><UOM><Quantity>0.0</Quantity></UOM></Weight>' . "\n";
        $xml .= '        <Cartons><UOM><Quantity>' . $totalQuantity . '</Quantity></UOM></Cartons>' . "\n";
        $xml .= '        <Volume><UOM><Quantity>0.0</Quantity></UOM></Volume>' . "\n";
        $xml .= '      </InvoiceTotals>' . "\n";
        $xml .= '      <TransactionSetPurposeCode>00</TransactionSetPurposeCode>' . "\n";
        $xml .= '    </Header>' . "\n";
        
        // Bill-To Address (BT)
        $xml .= '    <n>' . "\n";
        $xml .= '      <BillAndShipToCode>BT</BillAndShipToCode>' . "\n";
        if (!empty($invoice['billTo']['storeNumber'] ?? '')) {
            $xml .= '      <DUNSOrLocationNumber>' . xmlEscape($invoice['billTo']['storeNumber']) . '</DUNSOrLocationNumber>' . "\n";
        }
        $xml .= '      <CompanyName>' . xmlEscape($invoice['billTo']['name'] ?? '') . '</CompanyName>' . "\n";
        $xml .= '      <Address>' . xmlEscape($invoice['billTo']['street'] ?? '') . '</Address>' . "\n";
        $xml .= '      <City>' . xmlEscape($invoice['billTo']['city'] ?? '') . '</City>' . "\n";
        $xml .= '      <State>' . xmlEscape($invoice['billTo']['state'] ?? '') . '</State>' . "\n";
        $xml .= '      <Zip>' . xmlEscape($invoice['billTo']['zip'] ?? '') . '</Zip>' . "\n";
        if (!empty($invoice['billTo']['country'])) {
            $xml .= '      <Country>' . xmlEscape($invoice['billTo']['country']) . '</Country>' . "\n";
        }
        $xml .= '    </n>' . "\n";
        
        // Ship-To Address (ST)
        $xml .= '    <n>' . "\n";
        $xml .= '      <BillAndShipToCode>ST</BillAndShipToCode>' . "\n";
        if (!empty($invoice['shipTo']['storeNumber'])) {
            $xml .= '      <DUNSOrLocationNumber>' . xmlEscape($invoice['shipTo']['storeNumber']) . '</DUNSOrLocationNumber>' . "\n";
        }
        $xml .= '      <CompanyName>' . xmlEscape($invoice['shipTo']['name'] ?? '') . '</CompanyName>' . "\n";
        $xml .= '      <Address>' . xmlEscape($invoice['shipTo']['street'] ?? '') . '</Address>' . "\n";
        $xml .= '      <City>' . xmlEscape($invoice['shipTo']['city'] ?? '') . '</City>' . "\n";
        $xml .= '      <State>' . xmlEscape($invoice['shipTo']['state'] ?? '') . '</State>' . "\n";
        $xml .= '      <Zip>' . xmlEscape($invoice['shipTo']['zip'] ?? '') . '</Zip>' . "\n";
        if (!empty($invoice['shipTo']['country'])) {
            $xml .= '      <Country>' . xmlEscape($invoice['shipTo']['country']) . '</Country>' . "\n";
        }
        $xml .= '    </n>' . "\n";
        
        // Detail Lines
        $lineNumber = 1;
        foreach ($invoice['lineItems'] as $line) {
            $xml .= '    <Detail>' . "\n";
            $xml .= '      <DetailLine>' . "\n";
            $xml .= '        <InternalLineNumber>' . $lineNumber . '</InternalLineNumber>' . "\n";
            $xml .= '        <CustomerLineNumber>' . str_pad($line['lineNo'] ?? $lineNumber, 4, '0', STR_PAD_LEFT) . '</CustomerLineNumber>' . "\n";
            $xml .= '        <OriginalLineNumber>' . $lineNumber . '</OriginalLineNumber>' . "\n";
            
            // Item IDs
            if (!empty($line['itemNo'])) {
                $xml .= '        <ItemIDs>' . "\n";
                $xml .= '          <IdQualifier>UP</IdQualifier>' . "\n";
                $xml .= '          <Id>' . xmlEscape($line['itemNo']) . '</Id>' . "\n";
                $xml .= '        </ItemIDs>' . "\n";
            }
            
            // Quantities
            $xml .= '        <Quantities>' . "\n";
            $xml .= '          <QtyQualifier>39</QtyQualifier>' . "\n";
            $xml .= '          <QtyUOM>' . xmlEscape($line['unitOfMeasure'] ?? 'CA') . '</QtyUOM>' . "\n";
            $xml .= '          <Qty>' . ($line['quantity'] ?? 0) . '</Qty>' . "\n";
            $xml .= '        </Quantities>' . "\n";
            
            $xml .= '        <Quantities>' . "\n";
            $xml .= '          <QtyQualifier>38</QtyQualifier>' . "\n";
            $xml .= '          <QtyUOM>' . xmlEscape($line['unitOfMeasure'] ?? 'CA') . '</QtyUOM>' . "\n";
            $xml .= '          <Qty>' . ($line['quantity'] ?? 0) . '</Qty>' . "\n";
            $xml .= '        </Quantities>' . "\n";
            
            // Price/Cost
            $xml .= '        <PriceCost>' . "\n";
            $xml .= '          <PriceOrCost>' . number_format($line['unitPrice'] ?? 0, 2, '.', '') . '</PriceOrCost>' . "\n";
            $xml .= '          <PriceBasicQualifier>UCP</PriceBasicQualifier>' . "\n";
            $xml .= '        </PriceCost>' . "\n";
            
            // Item Description
            if (!empty($line['description'])) {
                $xml .= '        <ItemDescription>' . xmlEscape($line['description']) . '</ItemDescription>' . "\n";
            }
            
            // Line Totals
            $xml .= '        <LineTotals>' . "\n";
            $xml .= '          <TotalAmount>' . number_format($line['lineAmount'] ?? 0, 1, '.', '') . '</TotalAmount>' . "\n";
            $xml .= '          <TotalSublines>0</TotalSublines>' . "\n";
            $xml .= '        </LineTotals>' . "\n";
            
            $xml .= '      </DetailLine>' . "\n";
            $xml .= '    </Detail>' . "\n";
            
            $lineNumber++;
        }
        
        // Payment Terms
        if (!empty($invoice['paymentTerms'])) {
            $xml .= '    <Term>' . "\n";
            $xml .= '      <TermsType>01</TermsType>' . "\n";
            $xml .= '      <TermsBasis>3</TermsBasis>' . "\n";
            if (!empty($invoice['dueDate'])) {
                $xml .= '      <NetDueDate>' . formatDateForXML($invoice['dueDate']) . '</NetDueDate>' . "\n";
            }
            $xml .= '    </Term>' . "\n";
        }
        
        $xml .= '  </Document>' . "\n";
    }
    
    $xml .= '</File>';
    
    return $xml;
}

/**
 * Escape XML special characters
 */
function xmlEscape($text) {
    if ($text === null) return '';
    return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/**
 * Format date from M/D/YYYY to YYYY-MM-DD
 */
function formatDateForXML($date) {
    if (empty($date)) return date('Y-m-d');
    
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return $date;
    }
    
    $parts = explode('/', $date);
    if (count($parts) === 3) {
        return sprintf('%04d-%02d-%02d', $parts[2], $parts[0], $parts[1]);
    }
    
    return date('Y-m-d');
}

// Main Processing
try {
    logMessage('═══════════════════════════════════════════════════════════');
    logMessage('📥 EDI 810 Invoice Export - Lingo XML Format');
    logMessage('Time: ' . date('Y-m-d H:i:s'));
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Only POST method is allowed');
    }
    
    // Read JSON input
    $jsonInput = file_get_contents('php://input');
    if (empty($jsonInput)) {
        throw new Exception('No JSON data received');
    }
    
    logMessage('Received: ' . strlen($jsonInput) . ' bytes');
    
    // Decode JSON
    $data = json_decode($jsonInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON: ' . json_last_error_msg());
    }
    
    if (!isset($data['invoices']) || !is_array($data['invoices'])) {
        throw new Exception('Missing invoices array');
    }
    
    $invoices = $data['invoices'];
    $invoiceCount = count($invoices);
    
    if ($invoiceCount === 0) {
        throw new Exception('No invoices to process');
    }
    
    logMessage("Processing $invoiceCount invoice(s)");
    
    // Convert to Lingo XML
    $xmlContent = convertToLingoXML($invoices, $COMPANY_CODE);
    $xmlSize = strlen($xmlContent);
    logMessage("XML generated: $xmlSize bytes");
    
    // Generate filename
    $timestamp = date('YmdHis');
    $filename = "EDI810_Invoice_{$timestamp}.xml";
    
    // Save locally first
    if (!is_dir($LOCAL_OUTPUT_DIR)) {
        mkdir($LOCAL_OUTPUT_DIR, 0755, true);
    }
    $localPath = $LOCAL_OUTPUT_DIR . '/' . $filename;
    file_put_contents($localPath, $xmlContent);
    logMessage("Saved locally: $localPath");
    
    // Upload to SFTP
    $uploadSuccess = false;
    $remotePath = '';
    
    try {
        logMessage("Connecting to SFTP...");
        $sftp = new SFTPConnection($SFTP_CONFIG);
        $sftp->connect();
        logMessage("Connected to SFTP");
        
        // Create remote directory if needed
        $sftp->createDirectory($SFTP_CONFIG['remote_path']);
        
        // Upload file
        $remotePath = $SFTP_CONFIG['remote_path'] . '/' . $filename;
        logMessage("Uploading to: $remotePath");
        
        $sftp->uploadFile($localPath, $remotePath);
        $sftp->disconnect();
        
        $uploadSuccess = true;
        logMessage("Uploaded to SFTP: $remotePath");
        
    } catch (Exception $e) {
        logMessage("SFTP upload failed: " . $e->getMessage());
        $remotePath = $localPath; // Fallback to local path
    }
    
    logMessage('═══════════════════════════════════════════════════════════');
    
    // Success response
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'filesCreated' => 1,
        'filename' => $filename,
        'uploadedToSFTP' => $uploadSuccess,
        'remotePath' => $uploadSuccess ? $remotePath : $localPath,
        'localPath' => $localPath,
        'filesize' => $xmlSize,
        'invoiceCount' => $invoiceCount,
        'format' => 'Lingo XML (EDI 810)',
        'companyCode' => $COMPANY_CODE,
        'sftpHost' => $SFTP_CONFIG['host'],
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    $errorMsg = $e->getMessage();
    logMessage('Error: ' . $errorMsg);
    logMessage('═══════════════════════════════════════════════════════════');
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $errorMsg,
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT);
}
?>
