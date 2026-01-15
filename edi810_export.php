<?php
/**
 * EDI 810 Invoice Export Middleware
 * Receives JSON from Salesforce and converts to XML format
 * 
 * Usage: POST /edi810_export.php
 * Body: JSON array of invoices
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle OPTIONS request for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Configuration
$OUTPUT_DIR = __DIR__ . '/edi810_output';
$LOG_FILE = __DIR__ . '/edi810_export.log';

// Ensure output directory exists
if (!is_dir($OUTPUT_DIR)) {
    mkdir($OUTPUT_DIR, 0755, true);
}

/**
 * Log message to file
 */
function logMessage($message) {
    global $LOG_FILE;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($LOG_FILE, "[$timestamp] $message\n", FILE_APPEND);
}

/**
 * Convert JSON invoice to Excel XML format
 */
function convertToXML($invoices) {
    $xml = '<?xml version="1.0"?>' . "\n";
    $xml .= '<?mso-application progid="Excel.Sheet"?>' . "\n";
    $xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
    $xml .= ' xmlns:o="urn:schemas-microsoft-com:office:office"' . "\n";
    $xml .= ' xmlns:x="urn:schemas-microsoft-com:office:excel"' . "\n";
    $xml .= ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
    $xml .= ' xmlns:html="http://www.w3.org/TR/REC-html40">' . "\n";
    
    // Document Properties
    $xml .= ' <DocumentProperties xmlns="urn:schemas-microsoft-com:office:office">' . "\n";
    $xml .= '  <Author>EDI 810 Export System</Author>' . "\n";
    $xml .= '  <Created>' . date('Y-m-d\TH:i:s\Z') . '</Created>' . "\n";
    $xml .= '  <Version>16.00</Version>' . "\n";
    $xml .= ' </DocumentProperties>' . "\n";
    
    // Styles
    $xml .= ' <Styles>' . "\n";
    $xml .= '  <Style ss:ID="Default" ss:Name="Normal">' . "\n";
    $xml .= '   <Alignment ss:Vertical="Bottom"/>' . "\n";
    $xml .= '   <Borders/>' . "\n";
    $xml .= '   <Font ss:FontName="Calibri" ss:Size="11" ss:Color="#000000"/>' . "\n";
    $xml .= '   <Interior/>' . "\n";
    $xml .= '   <NumberFormat/>' . "\n";
    $xml .= '   <Protection/>' . "\n";
    $xml .= '  </Style>' . "\n";
    $xml .= ' </Styles>' . "\n";
    
    // Worksheet
    $xml .= ' <Worksheet ss:Name="EDI-810-Invoices">' . "\n";
    
    // Calculate total rows
    $totalRows = 1; // Header row
    foreach ($invoices as $invoice) {
        $totalRows += count($invoice['lineItems']);
    }
    
    $xml .= '  <Table ss:ExpandedColumnCount="4" ss:ExpandedRowCount="' . $totalRows . '">' . "\n";
    
    // Header Row
    $xml .= '   <Row>' . "\n";
    $xml .= '    <Cell><Data ss:Type="String">';
    $xml .= 'InvoiceNo|InvoiceDate|DueDate|PONumber|CustomerNo|CustomerName|';
    $xml .= 'TotalAmount|TaxAmount|PaymentTerms|';
    $xml .= 'BillToName|BillToStreet|BillToCity|BillToState|BillToZip|BillToCountry|';
    $xml .= 'ShipToStoreNo|ShipToName|ShipToStreet|ShipToCity|ShipToState|ShipToZip|ShipToCountry|';
    $xml .= 'LineNo|ItemNo|Description|Quantity|UnitPrice|LineAmount|UnitOfMeasure|LineTaxAmount';
    $xml .= '</Data></Cell>' . "\n";
    $xml .= '   </Row>' . "\n";
    
    // Data Rows
    foreach ($invoices as $invoice) {
        foreach ($invoice['lineItems'] as $line) {
            $xml .= '   <Row>' . "\n";
            
            // Build pipe-delimited data
            $data = implode('|', [
                $invoice['invoiceNumber'] ?? '',
                $invoice['invoiceDate'] ?? '',
                $invoice['dueDate'] ?? '',
                $invoice['poNumber'] ?? '',
                $invoice['customerNo'] ?? '',
                xmlEscape($invoice['customerName'] ?? ''),
                $invoice['totalAmount'] ?? '0',
                $invoice['taxAmount'] ?? '0',
                $invoice['paymentTerms'] ?? '',
                xmlEscape($invoice['billTo']['name'] ?? ''),
                xmlEscape($invoice['billTo']['street'] ?? ''),
                xmlEscape($invoice['billTo']['city'] ?? ''),
                $invoice['billTo']['state'] ?? '',
                $invoice['billTo']['zip'] ?? '',
                $invoice['billTo']['country'] ?? '',
                $invoice['shipTo']['storeNumber'] ?? '',
                xmlEscape($invoice['shipTo']['name'] ?? ''),
                xmlEscape($invoice['shipTo']['street'] ?? ''),
                xmlEscape($invoice['shipTo']['city'] ?? ''),
                $invoice['shipTo']['state'] ?? '',
                $invoice['shipTo']['zip'] ?? '',
                $invoice['shipTo']['country'] ?? '',
                $line['lineNo'] ?? '',
                $line['itemNo'] ?? '',
                xmlEscape($line['description'] ?? ''),
                $line['quantity'] ?? '0',
                $line['unitPrice'] ?? '0',
                $line['lineAmount'] ?? '0',
                $line['unitOfMeasure'] ?? '',
                $line['taxAmount'] ?? '0'
            ]);
            
            $xml .= '    <Cell><Data ss:Type="String">' . $data . '</Data></Cell>' . "\n";
            $xml .= '   </Row>' . "\n";
        }
    }
    
    $xml .= '  </Table>' . "\n";
    $xml .= ' </Worksheet>' . "\n";
    $xml .= '</Workbook>';
    
    return $xml;
}

/**
 * Escape XML special characters
 */
function xmlEscape($text) {
    if ($text === null) return '';
    $text = str_replace('&', '&amp;', $text);
    $text = str_replace('<', '&lt;', $text);
    $text = str_replace('>', '&gt;', $text);
    $text = str_replace('"', '&quot;', $text);
    $text = str_replace("'", '&apos;', $text);
    return $text;
}

// Main Processing
try {
    logMessage('EDI 810 export request received');
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Only POST method is allowed');
    }
    
    // Read JSON input
    $jsonInput = file_get_contents('php://input');
    
    if (empty($jsonInput)) {
        throw new Exception('No JSON data received');
    }
    
    logMessage('Received ' . strlen($jsonInput) . ' bytes of JSON data');
    
    // Decode JSON
    $data = json_decode($jsonInput, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON: ' . json_last_error_msg());
    }
    
    if (!isset($data['invoices']) || !is_array($data['invoices'])) {
        throw new Exception('Invalid data structure: missing invoices array');
    }
    
    $invoices = $data['invoices'];
    $invoiceCount = count($invoices);
    
    logMessage("Processing $invoiceCount invoice(s)");
    
    if ($invoiceCount === 0) {
        throw new Exception('No invoices to process');
    }
    
    // Convert to XML
    $xmlContent = convertToXML($invoices);
    
    // Generate filename
    $timestamp = date('YmdHis');
    $filename = "EDI810_Export_$timestamp.xml";
    $filepath = $OUTPUT_DIR . '/' . $filename;
    
    // Save XML file
    $bytesWritten = file_put_contents($filepath, $xmlContent);
    
    if ($bytesWritten === false) {
        throw new Exception('Failed to write XML file');
    }
    
    logMessage("✅ Successfully created $filename ($bytesWritten bytes)");
    
    // Success response
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'filesCreated' => 1,
        'filename' => $filename,
        'filepath' => $filepath,
        'filesize' => $bytesWritten,
        'invoiceCount' => $invoiceCount,
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    logMessage('❌ Error: ' . $e->getMessage());  // ✅ FIXED - use dot (.)
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
?>