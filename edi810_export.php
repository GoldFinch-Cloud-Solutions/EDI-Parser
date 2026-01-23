<?php
/**
 * EDI 810 Invoice SFTP Gateway
 * Only handles writing XML files to SFTP for EDI 810 export
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, username, password, sftp-host, sftp-port');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// SFTP Configuration from headers
$SFTP_CONFIG = [
    'host' => $_SERVER['HTTP_SFTP_HOST'] ?? getenv('SFTP_HOST'),
    'port' => intval($_SERVER['HTTP_SFTP_PORT'] ?? getenv('SFTP_PORT') ?: 22),
    'username' => $_SERVER['HTTP_USERNAME'] ?? getenv('SFTP_USERNAME'),
    'password' => $_SERVER['HTTP_PASSWORD'] ?? getenv('SFTP_PASSWORD'),
    'remote_path' => (isset($_SERVER['HTTP_IS_PRODUCTION']) && $_SERVER['HTTP_IS_PRODUCTION'] === 'false')
        ? (getenv('SFTP_UAT_REMOTE_PATH_INVOICE') ?: '/TSP/UAT/EDI810_Invoices')
        : (getenv('SFTP_REMOTE_PATH_INVOICE') ?: '/TSP/UAT/EDI810_Invoices')
];

// Validate configuration
if (empty($SFTP_CONFIG['host']) || empty($SFTP_CONFIG['username']) || empty($SFTP_CONFIG['password'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Missing SFTP credentials in headers'
    ]);
    exit();
}

/**
 * SFTP Connection Class
 */
class SFTPConnection {
    public $sftp;
    private $config;
    private $connection;
    
    public function __construct($config) {
        $this->config = $config;
    }
    
    public function connect() {
        // Try phpseclib first
        if (class_exists('phpseclib3\Net\SFTP')) {
            $this->sftp = new \phpseclib3\Net\SFTP($this->config['host'], $this->config['port']);
            if (!$this->sftp->login($this->config['username'], $this->config['password'])) {
                throw new Exception('SFTP login failed (phpseclib)');
            }
            return true;
        }
        
        // Try ssh2 extension
        if (function_exists('ssh2_connect')) {
            $this->connection = ssh2_connect($this->config['host'], $this->config['port']);
            if (!$this->connection) {
                throw new Exception('SSH2 connection failed');
            }
            if (!ssh2_auth_password($this->connection, $this->config['username'], $this->config['password'])) {
                throw new Exception('SSH2 authentication failed');
            }
            $this->sftp = ssh2_sftp($this->connection);
            if (!$this->sftp) {
                throw new Exception('SSH2 SFTP initialization failed');
            }
            return true;
        }
        
        // Try cURL as fallback
        if (function_exists('curl_init')) {
            $this->sftp = 'curl';
            return true;
        }
        
        throw new Exception('No SFTP method available');
    }
    
    public function disconnect() {
        $this->connection = null;
        $this->sftp = null;
    }
    
    public function writeFile($remotePath, $content) {
        if (is_object($this->sftp)) {
            // phpseclib
            return $this->sftp->put($remotePath, $content);
        } elseif ($this->sftp === 'curl') {
            // cURL
            return $this->writeFileCurl($remotePath, $content);
        } else {
            // SSH2
            return $this->writeFileSsh2($remotePath, $content);
        }
    }
    
    private function writeFileCurl($remotePath, $content) {
        $url = sprintf(
            "sftp://%s:%d%s",
            $this->config['host'],
            $this->config['port'],
            str_replace(' ', '%20', $remotePath)
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
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        
        curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($stream);
        
        if ($error) {
            throw new Exception("SFTP cURL Error: $error");
        }
        
        return true;
    }
    
    private function writeFileSsh2($remotePath, $content) {
        $stream = fopen("ssh2.sftp://{$this->sftp}" . $remotePath, 'w');
        if (!$stream) {
            throw new Exception("Failed to open remote file for writing");
        }
        
        $bytes = fwrite($stream, $content);
        fclose($stream);
        
        if ($bytes === false) {
            throw new Exception("Failed to write content to remote file");
        }
        
        return true;
    }
}

// Main logic
try {
    // Only accept POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Only POST method is supported");
    }
    
    // Get JSON body
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON: " . json_last_error_msg());
    }
    
    if (!$input || !isset($input['fileName']) || !isset($input['content'])) {
        throw new Exception("Missing 'fileName' or 'content' in request body");
    }
    
    $fileName = basename($input['fileName']);
    $content = $input['content'];
    
    // Validate
    if (!preg_match('/\.xml$/i', $fileName)) {
        throw new Exception("Only XML files are supported");
    }
    
    if (empty($content)) {
        throw new Exception("File content cannot be empty");
    }
    
    // Connect and write
    $sftp = new SFTPConnection($SFTP_CONFIG);
    $sftp->connect();
    
    $remotePath = $SFTP_CONFIG['remote_path'] . '/' . $fileName;
    $sftp->writeFile($remotePath, $content);
    $sftp->disconnect();
    
    // Success response
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'fileName' => $fileName,
        'path' => $remotePath,
        'size' => strlen($content),
        'message' => 'File written successfully to SFTP'
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>

