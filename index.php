<?php
/**
 * WooCommerce Orders XML Parser API - COMPLETE DUAL FORMAT SUPPORT
 * Supports both Excel XML and Lingo XML formats
 * 
 * Version: 2.0
 * Last Updated: January 2026
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Check if this is a move-files request
    if (isset($input['sourcePath']) && isset($input['destinationPath'])) {
        $SFTP_CONFIG = [
            'host' => $_SERVER['HTTP_SFTP_HOST'] ?? getenv('SFTP_HOST'),
            'port' => $_SERVER['HTTP_SFTP_PORT'] ?? getenv('SFTP_PORT') ?: 22,
            'username' => $_SERVER['HTTP_USERNAME'] ?? getenv('SFTP_USERNAME'),
            'password' => $_SERVER['HTTP_PASSWORD'] ?? getenv('SFTP_PASSWORD')
        ];
        
        $sourcePath = $input['sourcePath'];
        $destinationPath = $input['destinationPath'];
        
        try {
            $sftp = new SFTPConnection($SFTP_CONFIG);
            $sftp->connect();
            
            $files = $sftp->listFiles($sourcePath);
            $movedCount = 0;
            
            foreach ($files as $file) {
                $sourceFile = rtrim($sourcePath, '/') . '/' . $file['filename'];
                $destFile = rtrim($destinationPath, '/') . '/' . $file['filename'];
                
                $content = $sftp->getFileContent($sourceFile);
                
                $tempFile = sys_get_temp_dir() . '/' . $file['filename'];
                file_put_contents($tempFile, $content);
                
                $sftp->uploadFile($tempFile, $destFile);
                $sftp->deleteFile($sourceFile);
                
                unlink($tempFile);
                $movedCount++;
            }
            
            $sftp->disconnect();
            
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'filesMoved' => $movedCount
            ]);
            
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
        exit;  // Important: exit after handling move-files
    }
}
//$SFTP_CONFIG = [
//    'host' => getenv('SFTP_HOST') ?: 'virginia.sftptogo.com',
//    'port' => getenv('SFTP_PORT') ?: 22,
//    'username' => getenv('SFTP_USERNAME') ?: 'def00441166779c394b1ebf405d60a',
//    'password' => getenv('SFTP_PASSWORD') ?: '7Ivut003QohHdnzxzsPzKbkTbGGWHj',
//    'remote_path' => getenv('SFTP_REMOTE_PATH') ?: '/EDI_Orders'
//];

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
        if (function_exists('curl_init') && $this->checkCurlSFTPSupport()) {
            $this->sftp = 'curl';
            return true;
        }
        elseif (function_exists('ssh2_connect')) {
            return $this->connectNative();
        } else {
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
            $handle = opendir("ssh2.sftp://{$this->sftp}" . $remotePath);
            while (false !== ($file = readdir($handle))) {
                if ($file === '.' || $file === '..') continue;
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
            return file_get_contents($sftpPath);
        }
    }
    
    private function getFileContentWithCurl($remoteFile) {
        $encodedPath = str_replace(' ', '%20', $remoteFile);
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
    
    /**
     * Auto-detect format and parse accordingly
     */
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
    
    /**
     * Parse Excel XML format 
     */
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
    
    /**
     * Parse Lingo XML format - COMPLETE WITH ALL TAGS
     */
    private function parseLingoXML($xmlContent) {
        $xml = simplexml_load_string($xmlContent);
        if ($xml === false) throw new Exception("Failed to parse Lingo XML");
        
        $orders = [];
        
        foreach ($xml->Document as $document) {
            $order = [
                // Document Level Tags - ALL INCLUDED
                'documentId' => (string)$document->InternalDocumentNumber ?: (string)$document->InternalOrderNumber,
                'companyCode' => (string)$document->CompanyCode,
                'customerNo' => (string)$document->CustomerNumber,
                'poNumber' => (string)$document->PurchaseOrderNumber,
                'poSourceId' => (string)$document->PurchaseOrderSourceID,
                'locationNumber' => (string)$document->LocationNumber,
                'direction' => (string)$document->Direction,
                'documentType' => (string)$document->DocumentType,
                'footprint' => (string)$document->Footprint,
                'version' => (string)$document->Version,
                'vendorNo' => '',
                'poDate' => $this->formatLingoDate((string)$document->PurchaseOrderDate),
                'shipDateOrder' => '',
                'cancelDate' => '',
                'requestedDeliveryDate' => '',
                'orderAmount' => 0,
                'orderCases' => 0,
                'orderWeight' => 0,
                'totalLines' => 0,
                'methodOfPayment' => '',
                'transportationMethodCode' => '',
                'customerAccountNumber' => '',
                'billTo' => [],
                'shipTo' => [],
                'shipFrom' => [],
                'remitTo' => [],
                'vendor' => [],
                'terms' => [],
                'notes' => [],
                'lineItems' => []
            ];
            
            // Parse Header section - ALL TAGS
            if (isset($document->Header)) {
                $header = $document->Header;
                
                // Basic Header Fields
                $order['poDate'] = $this->formatLingoDate((string)$header->PurchaseOrderDate) ?: $order['poDate'];
                $order['vendorNo'] = (string)$header->VendorNumber;
                $order['customerAccountNumber'] = (string)$header->CustomerAccountNumber;
                $order['transportationMethodCode'] = (string)$header->TransportationMethodCode;
                
                // Order Totals
                if (isset($header->OrderTotals)) {
                    $order['orderAmount'] = (float)$header->OrderTotals->TotalAmount;
                    $order['totalLines'] = (int)$header->OrderTotals->TotalLines;
                    
                    // Weight
                    if (isset($header->OrderTotals->Weight->UOM->Quantity)) {
                        $order['orderWeight'] = (float)$header->OrderTotals->Weight->UOM->Quantity;
                    }
                    
                    // Cartons/Cases
                    if (isset($header->OrderTotals->Cartons->UOM->Quantity)) {
                        $order['orderCases'] = (int)$header->OrderTotals->Cartons->UOM->Quantity;
                    }
                }
                
                // Date Loops - Extract ALL date types
                foreach ($header->DateLoop as $dateLoop) {
                    $qualifier = (string)$dateLoop->DateQualifier;
                    $qualifierDesc = (string)$dateLoop->DateQualifier['Desc'];
                    $date = $this->formatLingoDate((string)$dateLoop->Date);
                    
                    switch ($qualifier) {
                        case '004': // PO Date
                            $order['poDate'] = $date;
                            break;
                        case '010': // Ship Date
                        case '011':
                            $order['shipDateOrder'] = $date;
                            break;
                        case '001': // Cancel Date
                            $order['cancelDate'] = $date;
                            break;
                        case '074': // Requested Delivery Date
                            $order['requestedDeliveryDate'] = $date;
                            break;
                    }
                }
                
                // Notes - Capture ALL notes
                foreach ($header->Note as $note) {
                    $order['notes'][] = [
                        'sequenceNumber' => (string)$note->SequenceNumber,
                        'detailLineNumber' => (string)$note->DetailLineNumber,
                        'text' => (string)$note->Text,
                        'type' => (string)$note->Type,
                        'typeDesc' => (string)$note->Type['Desc']
                    ];
                }
            }
            
            // Parse Name/Address sections
            foreach ($document->n as $address) {
                $code = (string)$address->BillAndShipToCode;
                
                $addressData = [
                    'storeNumber' => (string)$address->DUNSOrLocationNumber,
                    'dunsQualifier' => (string)$address->DUNSQualifier,
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
                    case 'BT': // Bill-To
                        $order['billTo'] = $addressData;
                        break;
                    case 'ST': // Ship-To
                        $order['shipTo'] = $addressData;
                        break;
                    case 'SF': // Ship-From
                        $order['shipFrom'] = $addressData;
                        break;
                    case 'RE': // Remit-To
                        $order['remitTo'] = $addressData;
                        break;
                    case 'VN': // Vendor
                        $order['vendor'] = $addressData;
                        $order['vendorNo'] = $addressData['storeNumber'];
                        break;
                }
            }
            
            // Parse Detail/Line Items
            $lineNo = 1;
            foreach ($document->Detail as $detail) {
                $lineItem = $detail->DetailLine;
                
                // Item IDs - Extract ALL types (UP, UA, UK, etc.)
                $vendorItemNo = '';
                $upcCode = '';
                $caseUPC = '';
                $gtin = '';
                
                foreach ($lineItem->ItemIds as $itemId) {
                    $qualifier = (string)$itemId->IdQualifier;
                    $qualifierDesc = (string)$itemId->IdQualifier['Desc'];
                    $id = (string)$itemId->Id;
                    
                    switch ($qualifier) {
                        case 'UP': // Vendor Item / UPC Code
                            if ($qualifierDesc === 'UpcCode' || empty($vendorItemNo)) {
                                $upcCode = $id;
                            }
                            $vendorItemNo = $id;
                            break;
                        case 'UA': // Unit UPC / Case Code
                            if ($qualifierDesc === 'UpcCaseCode') {
                                $caseUPC = $id;
                            } else {
                                $upcCode = $id;
                            }
                            break;
                        case 'UK': // GTIN / Case UPC
                            if ($qualifierDesc === 'GTINNumber') {
                                $gtin = $id;
                            }
                            $caseUPC = $id;
                            break;
                    }
                }
                
                // Quantities - Extract ALL quantity types
                $quantity = 0;
                $quantityChanged = 0;
                $quantityAcked = 0;
                $quantityShipped = 0;
                $quantityInvoiced = 0;
                $originalQuantity = 0;
                $componentQuantity = 0;
                $uom = '';
                
                foreach ($lineItem->Quantities as $qty) {
                    $qualifier = (string)$qty->QtyQualifier;
                    $qualifierDesc = (string)$qty->QtyQualifier['Desc'];
                    $qtyValue = (int)$qty->Qty;
                    $qtyUOM = (string)$qty->QtyUOM;
                    
                    if (empty($uom)) $uom = $qtyUOM;
                    
                    switch ($qualifier) {
                        case '01': // Quantity Ordered
                        case '38': // Quantity Ordered
                            $quantity = $qtyValue;
                            break;
                        case 'ZZ': // Original Quantity
                            $originalQuantity = $qtyValue;
                            break;
                        case '31': // Quantity Changed
                            $quantityChanged = $qtyValue;
                            break;
                        case '27': // Quantity Acknowledged
                            $quantityAcked = $qtyValue;
                            break;
                        case '39': // Quantity Shipped
                            $quantityShipped = $qtyValue;
                            break;
                        case 'D1': // Quantity Invoiced
                            $quantityInvoiced = $qtyValue;
                            break;
                        case '9N': // Component Quantity
                            $componentQuantity = $qtyValue;
                            break;
                    }
                }
                
                // Price/Cost - ALL pricing fields
                $unitPrice = 0;
                $originalPrice = 0;
                $priceBasis = '';
                $priceBasisQualifier = '';
                
                if (isset($lineItem->PriceCost)) {
                    $unitPrice = (float)$lineItem->PriceCost->PriceOrCost;
                    $priceBasis = (string)$lineItem->PriceCost->PriceBasisCode;
                    $priceBasisQualifier = (string)$lineItem->PriceCost->PriceBasisQualifier;
                    $originalPrice = $unitPrice; // Default to unit price
                }
                
                // Pack Information - ALL pack fields
                $packSize = (string)$lineItem->PackSize;
                $inners = (string)$lineItem->Inners;
                $eachesPerInner = (string)$lineItem->EachesPerInner;
                $innersPerPack = (string)$lineItem->InnersPerPack;
                
                // Line Totals
                $lineTotal = 0;
                $totalSubLines = 0;
                if (isset($lineItem->LineTotals)) {
                    $lineTotal = (float)$lineItem->LineTotals->TotalAmount;
                    $totalSubLines = (int)$lineItem->LineTotals->TotalSubLines;
                }
                
                // Build Line Item
                $item = [
                    'lineNo' => str_pad((string)$lineItem->CustomerLineNumber ?: $lineNo, 4, '0', STR_PAD_LEFT),
                    'internalLineNumber' => (string)$lineItem->InternalLineNumber,
                    'originalLineNumber' => (string)$lineItem->OriginalLineNumber,
                    'vendorItemNo' => $vendorItemNo,
                    'upcCode' => $upcCode,
                    'caseUPC' => $caseUPC,
                    'gtin' => $gtin,
                    'customerItem' => '',
                    'quantityOrdered' => $quantity,
                    'originalQuantity' => $originalQuantity,
                    'quantityChanged' => $quantityChanged,
                    'quantityAcked' => $quantityAcked,
                    'quantityShipped' => $quantityShipped,
                    'quantityInvoiced' => $quantityInvoiced,
                    'componentQuantity' => $componentQuantity,
                    'unitMeasure' => $uom,
                    'unitPrice' => $unitPrice,
                    'originalPrice' => $originalPrice,
                    'sellingPrice' => $unitPrice,
                    'priceBasis' => $priceBasis,
                    'priceBasisQualifier' => $priceBasisQualifier,
                    'lineTotal' => $lineTotal ?: ($unitPrice * $quantity),
                    'totalSubLines' => $totalSubLines,
                    'packSize' => $packSize,
                    'inners' => $inners,
                    'eachesPerInner' => $eachesPerInner,
                    'innersPerPack' => $innersPerPack,
                    'itemDesc' => (string)$lineItem->ItemDescription,
                    'itemDesc2' => '',
                    'sku' => '',
                    'upcCaseCode' => $caseUPC,
                    'countryOfOrigin' => '',
                    'shipDateDetail' => '',
                    'notes' => [],
                    'chargesOrAllowances' => []
                ];
                
                $order['lineItems'][] = $item;
                
                // Line-level Notes
                foreach ($detail->Note as $note) {
                    if ((string)$note->DetailLineNumber === $item['lineNo']) {
                        $item['notes'][] = [
                            'sequenceNumber' => (string)$note->SequenceNumber,
                            'text' => (string)$note->Text,
                            'type' => (string)$note->Type,
                            'typeDesc' => (string)$note->Type['Desc']
                        ];
                    }
                }
                
                // Charge or Allowance
                foreach ($detail->ChargeOrAllowance as $charge) {
                    if ((string)$charge->DetailLineNumber === $item['lineNo']) {
                        $item['chargesOrAllowances'][] = [
                            'sequenceNumber' => (string)$charge->SequenceNumber,
                            'indicator' => (string)$charge->Indicator,
                            'indicatorDesc' => (string)$charge->Indicator['Desc'],
                            'specialServiceCode' => (string)$charge->SpecialServiceCode,
                            'specialServiceDesc' => (string)$charge->SpecialServiceCode['Desc'],
                            'methodOfHandlingCode' => (string)$charge->MethodOfHandlingCode,
                            'methodOfHandlingDesc' => (string)$charge->MethodOfHandlingCode['Desc'],
                            'totalAmount' => (float)$charge->TotalAmount
                        ];
                    }
                }
                
                $lineNo++;
            }
            
            // Parse Terms - ALL term fields
            if (isset($document->Term)) {
                $order['terms'] = [
                    'termType' => (string)$document->Term->TermsType,
                    'basis' => (string)$document->Term->TermsBasis,
                    'netDueDate' => $this->formatLingoDate((string)$document->Term->NetDueDate),
                    'netDueDays' => (int)$document->Term->NetDueDays,
                    'discountDays' => (int)$document->Term->DiscountDays,
                    'discountPercent' => (float)$document->Term->DiscountPercent,
                    'discountAmount' => (float)$document->Term->DiscountAmount
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
    
    /**
     * Format Lingo date (YYYY-MM-DD) to M/D/YYYY
     */
    private function formatLingoDate($dateStr) {
        if (empty($dateStr)) return '';
        
        // Already in M/D/YYYY format
        if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $dateStr)) {
            return $dateStr;
        }
        
        // Convert YYYY-MM-DD to M/D/YYYY
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dateStr, $matches)) {
            return intval($matches[2]) . '/' . intval($matches[3]) . '/' . $matches[1];
        }
        
        return $dateStr;
    }
    
    /**
     * Parse Excel header row
     */
    private function parseExcelHeaders($headerRow) {
        $dataCells = $headerRow->xpath('.//ss:Data');
        if (empty($dataCells)) throw new Exception("No header data found");
        $headerString = (string)$dataCells[0];
        $this->headers = explode('|', $headerString);
    }
    
    /**
     * Parse Excel data rows
     */
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
 * Parse files from SFTP
 */
function parseSFTPFiles($config, $parser) {
    $sftp = new SFTPConnection($config);
    $sftp->connect();
    
    $files = $sftp->listFiles($config['remote_path']);
    if (empty($files)) throw new Exception("No XML files found on SFTP server");
    
    $allOrders = [];
    $filesProcessed = 0;
    $fileErrors = [];
    $formats = [];
    
    foreach ($files as $fileInfo) {
        try {
            $xmlContent = $sftp->getFileContent($fileInfo['path']);
            $result = $parser->parseXML($xmlContent);
            
            $formats[] = $result['format'];
            
            foreach ($result['orders'] as $order) {
                $order['sourceFile'] = $fileInfo['filename'];
                $order['sourceType'] = 'sftp';
                $order['sourceFormat'] = $result['format'];
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
    
    $totalLineItems = 0;
    foreach ($allOrders as $order) {
        $totalLineItems += count($order['lineItems']);
    }
    
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
        'fileErrors' => $fileErrors,
        'orders' => $allOrders,
    ];
}

// Main API Logic
try {
    $parser = new OrderXMLParser();
    $result = null;
    $directory = __DIR__ . '/orders';
    
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (isset($_GET['action']) && $_GET['action'] === 'parseSftp') {
            $result = parseSFTPFiles($SFTP_CONFIG, $parser);
        } else {
            $fileName = $_GET['file'] ?? 'wc-orders.xml';
            $filePath = $directory . '/' . basename($fileName);
            $result = $parser->parseFile($filePath);
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
            $result = $parser->parseFile($_FILES['file']['tmp_name']);
        } else {
            $xmlContent = file_get_contents('php://input');
            if (empty($xmlContent)) throw new Exception("No XML content provided");
            $result = $parser->parseXML($xmlContent);
        }
    } else {
        throw new Exception("Method not allowed");
    }
    
    http_response_code(200);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
?>



