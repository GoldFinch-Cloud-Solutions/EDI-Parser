<?php
$curlInfo = curl_version();
echo in_array('sftp', $curlInfo['protocols']) ? 'YES - cURL supports SFTP!' : 'NO - cURL does not support SFTP';