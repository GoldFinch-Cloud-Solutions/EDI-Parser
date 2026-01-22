<?php
/**
 * EDI 810 Invoice SFTP Gateway
 * Handles SFTP operations for EDI 810 invoice export
 * 
 * Endpoints:
 * POST /edi810_export.php (body: {fileName, content})
 * GET  /edi810_export.php?action=list
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, username, password, sftp-host, sftp-port');

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
    'remote_path' => '/EDI810_Invoices'
];

// Reuse SFTPConnection class from index.php
require_once __DIR__ . '/index.php';

try {
    $result = null;
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Write XML file to SFTP
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input || !isset($input['fileName']) || !isset($input['content'])) {
            throw new Exception("Missing fileName or content in request body");
        }
        
        $fileName = basename($input['fileName']);
        $content = $input['content'];
        
        if (!preg_match('/\.xml$/i', $fileName)) {
            throw new Exception("Only XML files are supported");
        }
        
        // Connect and write
        $sftp = new SFTPConnection($SFTP_CONFIG);
        $sftp->connect();
        
        $remotePath = $SFTP_CONFIG['remote_path'] . '/' . $fileName;
        
        // Write based on connection type
        if (is_object($sftp->sftp)) {
            // phpseclib
            $sftp->sftp->put($remotePath, $content);
        } elseif ($sftp->sftp === 'curl') {
            // cURL
            $encodedPath = str_replace(' ', '%20', $remotePath);
            $url = sprintf(
                "sftp://%s:%d%s",
                $SFTP_CONFIG['host'],
                $SFTP_CONFIG['port'],
                $encodedPath
            );
            
            $ch = curl_init();
            $stream = fopen('php://temp', 'r+');
            fwrite($stream, $content);
            rewind($stream);
            
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_USERPWD, $SFTP_CONFIG['username'] . ':' . $SFTP_CONFIG['password']);
            curl_setopt($ch, CURLOPT_UPLOAD, true);
            curl_setopt($ch, CURLOPT_INFILE, $stream);
            curl_setopt($ch, CURLOPT_INFILESIZE, strlen($content));
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            
            curl_exec($ch);
            $error = curl_error($ch);
            curl_close($ch);
            fclose($stream);
            
            if ($error) {
                throw new Exception("SFTP cURL Error: $error");
            }
        } else {
            // SSH2
            $stream = fopen("ssh2.sftp://{$sftp->sftp}" . $remotePath, 'w');
            if (!$stream) {
                throw new Exception("Failed to open remote file for writing");
            }
            fwrite($stream, $content);
            fclose($stream);
        }
        
        $sftp->disconnect();
        
        $result = [
            'success' => true,
            'action' => 'write',
            'fileName' => $fileName,
            'path' => $remotePath,
            'size' => strlen($content),
            'message' => 'File written successfully'
        ];
        
    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (isset($_GET['action']) && $_GET['action'] === 'list') {
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
        } else {
            throw new Exception("Invalid GET action. Use ?action=list");
        }
    }
    
    header('Content-Type: application/json');
    http_response_code(200);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
?>
