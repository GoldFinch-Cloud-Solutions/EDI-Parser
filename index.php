<?php
/**
 * WooCommerce Orders XML File API
 * 
 * Usage: 
 * GET  /index.php?action=listSftp             (List files on SFTP server)
 * GET  /index.php?file=wc-orders.xml          (Download raw XML file from SFTP)
 * GET  /index.php?action=archive&file=wc-orders.xml  (Copy file to Archived subfolder)
 * GET  /index.php?action=delete&file=wc-orders.xml   (Delete file from main folder)
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
    'host' => $_SERVER['HTTP_SFTP_HOST'] ?? getenv('SFTP_HOST'),
    'port' => $_SERVER['HTTP_SFTP_PORT'] ?? getenv('SFTP_PORT') ?: 22,
    'username' => $_SERVER['HTTP_USERNAME'] ?? getenv('SFTP_USERNAME'),
    'password' => $_SERVER['HTTP_PASSWORD'] ?? getenv('SFTP_PASSWORD'),
    'remote_path' => (getenv('SFTP_REMOTE_PATH') ?? getenv('SFTP_REMOTE_PATH') ?: '/EDI850_Orders')
    // 'remote_path' => (isset($_SERVER['HTTP_IS_PRODUCTION']) && $_SERVER['HTTP_IS_PRODUCTION'] === 'false')
    //     ? (getenv('SFTP_UAT_REMOTE_PATH') ?: '/TSP/UAT/EDI850_Orders')
    //     : (getenv('SFTP_REMOTE_PATH') ?: '/TSP/UAT/EDI850_Orders')
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
     * Copy file on SFTP server
     */
    public function copyFile($sourceFile, $destFile) {
        if ($this->sftp === 'curl') {
            // cURL doesn't support direct copy, so download and upload
            $content = $this->getFileContentWithCurl($sourceFile);
            if ($content === false) {
                throw new Exception("Failed to read source file: $sourceFile");
            }
            return $this->uploadFileWithCurl($destFile, $content);
        }
        elseif (is_object($this->sftp)) {
            // Using phpseclib - get content and put to new location
            $content = $this->sftp->get($sourceFile);
            if ($content === false) {
                throw new Exception("Failed to read source file: $sourceFile");
            }
            return $this->sftp->put($destFile, $content);
        } else {
            // Using native SSH2
            $sftpPath = "ssh2.sftp://{$this->sftp}" . $sourceFile;
            $content = file_get_contents($sftpPath);
            if ($content === false) {
                throw new Exception("Failed to read source file: $sourceFile");
            }
            $destPath = "ssh2.sftp://{$this->sftp}" . $destFile;
            return file_put_contents($destPath, $content) !== false;
        }
    }
    
    /**
     * Delete file from SFTP server
     */
    public function deleteFile($remoteFile) {
        if ($this->sftp === 'curl') {
            // cURL delete using CURLOPT_QUOTE with RM command
            // Note: Use the raw path for the rm command, not URL-encoded
            $url = sprintf(
                "sftp://%s:%d/",
                $this->config['host'],
                $this->config['port']
            );
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_USERPWD, $this->config['username'] . ':' . $this->config['password']);
            curl_setopt($ch, CURLOPT_QUOTE, array("rm " . $remoteFile));
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            
            $result = curl_exec($ch);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($error) {
                throw new Exception("SFTP cURL Error: $error");
            }
            
            return true;
        }
        elseif (is_object($this->sftp)) {
            // Using phpseclib
            return $this->sftp->delete($remoteFile);
        } else {
            // Using native SSH2
            return ssh2_sftp_unlink($this->sftp, $remoteFile);
        }
    }
    
    /**
     * Upload file content using cURL
     */
    private function uploadFileWithCurl($remoteFile, $content) {
        $encodedPath = str_replace(' ', '%20', $remoteFile);
        $url = sprintf(
            "sftp://%s:%d%s",
            $this->config['host'],
            $this->config['port'],
            $encodedPath
        );
        
        $ch = curl_init();
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERPWD, $this->config['username'] . ':' . $this->config['password']);
        curl_setopt($ch, CURLOPT_UPLOAD, true);
        curl_setopt($ch, CURLOPT_INFILE, $stream);
        curl_setopt($ch, CURLOPT_INFILESIZE, strlen($content));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $result = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($stream);
        
        if ($error) {
            throw new Exception("SFTP cURL Error: $error");
        }
        
        return true;
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

// Main API Logic
try {
    $result = null;
    
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
            
            // Return JSON response
            header('Content-Type: application/json');
            http_response_code(200);
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }
        // Download raw XML file from SFTP
        elseif (isset($_GET['file']) && !isset($_GET['action'])) {
            $fileName = $_GET['file'];
            
            // Security: prevent directory traversal
            $fileName = basename($fileName);
            
            // Validate file extension
            if (!preg_match('/\.xml$/i', $fileName)) {
                throw new Exception("Only XML files are supported");
            }
            
            // Connect to SFTP and get file content
            $sftp = new SFTPConnection($SFTP_CONFIG);
            $sftp->connect();
            
            $remotePath = $SFTP_CONFIG['remote_path'] . '/' . $fileName;
            $xmlContent = $sftp->getFileContent($remotePath);
            $sftp->disconnect();
            
            if ($xmlContent === false || empty($xmlContent)) {
                throw new Exception("File not found or empty: $fileName");
            }
            
            // Return raw XML content
            header('Content-Type: application/xml');
            header('Content-Disposition: inline; filename="' . $fileName . '"');
            http_response_code(200);
            echo $xmlContent;
        }
        // Archive file (copy to Archived subfolder)
        elseif (isset($_GET['action']) && $_GET['action'] === 'archive' && isset($_GET['file'])) {
            $fileName = $_GET['file'];
            
            // Security: prevent directory traversal
            $fileName = basename($fileName);
            
            // Validate file extension
            if (!preg_match('/\.xml$/i', $fileName)) {
                throw new Exception("Only XML files are supported");
            }
            
            // Connect to SFTP and copy file
            $sftp = new SFTPConnection($SFTP_CONFIG);
            $sftp->connect();
            
            $sourceFile = $SFTP_CONFIG['remote_path'] . '/' . $fileName;
            $destFile = $SFTP_CONFIG['remote_path'] . '/Archived/' . $fileName;
            
            $sftp->copyFile($sourceFile, $destFile);
            $sftp->disconnect();
            
            $result = [
                'success' => true,
                'action' => 'archive',
                'fileName' => $fileName,
                'source' => $sourceFile,
                'destination' => $destFile,
                'message' => 'File copied to Archived folder successfully'
            ];
            
            // Return JSON response
            header('Content-Type: application/json');
            http_response_code(200);
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }
        // Delete file from main folder
        elseif (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['file'])) {
            $fileName = $_GET['file'];
            
            // Security: prevent directory traversal
            $fileName = basename($fileName);
            
            // Validate file extension
            if (!preg_match('/\.xml$/i', $fileName)) {
                throw new Exception("Only XML files are supported");
            }
            
            // Connect to SFTP and delete file
            $sftp = new SFTPConnection($SFTP_CONFIG);
            $sftp->connect();
            
            $remotePath = $SFTP_CONFIG['remote_path'] . '/' . $fileName;
            $sftp->deleteFile($remotePath);
            $sftp->disconnect();
            
            $result = [
                'success' => true,
                'action' => 'delete',
                'fileName' => $fileName,
                'path' => $remotePath,
                'message' => 'File deleted successfully'
            ];
            
            // Return JSON response
            header('Content-Type: application/json');
            http_response_code(200);
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }
        else {
            throw new Exception("Invalid request. Use ?action=listSftp to list files, ?file=filename.xml to download, ?action=archive&file=filename.xml to archive, or ?action=delete&file=filename.xml to delete");
        }
        
    } else {
        throw new Exception("Method not allowed. Only GET requests are supported");
    }
    
} catch (Exception $e) {
    // Error response
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}






