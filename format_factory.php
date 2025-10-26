<?php
require_once './vendor/autoload.php';
require_once './directory_checker.php';

use FFMpeg\FFMpeg;
use FFMpeg\Format\Video;
use FFMpeg\Format\Video\X264;
use FFMpeg\Format\Audio;

// --- Configuration ---

$start = microtime(true);
set_time_limit(120);

// Define base directories. IMPORTANT: Make sure these directories exist and are writable by the web server.
define('BASE_DIR', __DIR__);
define('UPLOADS_DIR', BASE_DIR . '/uploads');
define('CONVERTED_DIR', BASE_DIR . '/converted');
// Base URL for the converted files. Assumes 'converted' is web-accessible in the same dir.
define('CONVERTED_URL_BASE', '/converted');


// --- Relaxed Security Settings (for internal network use) ---
// WARNING: Do not expose a server with these settings to the public internet.
// Max download size in bytes (e.g., 2 * 1024 * 1024 * 1024 = 2GB)
define('MAX_DOWNLOAD_SIZE', 2 * 1024 * 1024 * 1024);
// Max download time in seconds (e.g., 300s = 5 minutes)
define('DOWNLOAD_TIMEOUT', 300);

// run directory checker
$logs = [];
$dir_logs = bootDirs();
$logs = array_merge($logs, $dir_logs);


// --- Environment Loading ---

// Simple .env file parser
function loadEnv($path)
{
    if (!file_exists($path)) {
        return [];
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $config = [];
    foreach ($lines as $line) {
        if (strpos(trim($line), ';') === 0 || strpos(trim($line), '#') === 0) {
            continue; // Skip comments
        }
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            // Remove quotes from value
            if (preg_match('/^"(.*)"$/', $value, $matches) || preg_match("/^'(.*)'$/", $value, $matches)) {
                $value = $matches[1];
            }
            $config[$name] = $value;
        }
    }
    return $config;
}

$env = loadEnv(BASE_DIR . '/.env');

// Determine OS and FFmpeg path
$isWindows = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
$ffmpegPath = '';
if ($isWindows) {
    $ffmpegPath = $env['FFMPEG_WINDOWS_PATH'] ?? 'ffmpeg'; // Fallback to 'ffmpeg' in PATH
} else {
    $ffmpegPath = $env['FFMPEG_LINUX_PATH'] ?? 'ffmpeg'; // Fallback to 'ffmpeg' in PATH
}

if (empty($ffmpegPath) || ($isWindows && $ffmpegPath === 'ffmpeg') || (!$isWindows && $ffmpegPath === 'ffmpeg')) {
    if (!file_exists(BASE_DIR . '/.env')) {
         jsonResponse(500, "Configuration error.", [], ["type" => "Server Error", "details" => ".env file not found. Please copy .env.example to .env and configure it."], $logs);
    }
     jsonResponse(500, "Configuration error.", [], ["type" => "Server Error", "details" => "FFmpeg path is not set in your .env file."], $logs);
}


// --- Allowed Formats ---

/**
 * Defines allowed conversion pairs.
 * Key is the *input* format, value is an array of *output* formats.
 */
$allowedConvertFormat = [
    'mp4'  => ['avi', 'mov', 'wmv', 'mkv', 'flv', 'webm', 'm4v', 'mp3', 'wav', 'aac', 'ogg', 'flac'],
    'avi'  => ['mp4', 'mov', 'wmv', 'mkv', 'flv', 'webm', 'm4v', 'mp3', 'wav', 'aac', 'ogg', 'flac'],
    'mov'  => ['mp4', 'avi', 'wmv', 'mkv', 'flv', 'webm', 'm4v', 'mp3', 'wav', 'aac', 'ogg', 'flac'],
    'wmv'  => ['mp4', 'avi', 'mov', 'mkv', 'flv', 'webm', 'm4v', 'mp3', 'wav', 'aac', 'ogg', 'flac'],
    'mkv'  => ['mp4', 'avi', 'mov', 'wmv', 'flv', 'webm', 'm4v', 'mp3', 'wav', 'aac', 'ogg', 'flac'],
    'flv'  => ['mp4', 'avi', 'mov', 'wmv', 'mkv', 'webm', 'm4v', 'mp3', 'wav', 'aac', 'ogg', 'flac'],
    'webm' => ['mp4', 'avi', 'mov', 'wmv', 'mkv', 'flv', 'm4v', 'mp3', 'wav', 'aac', 'ogg', 'flac'],
    'm4v'  => ['mp4', 'avi', 'mov', 'wmv', 'mkv', 'flv', 'webm', 'mp3', 'wav', 'aac', 'ogg', 'flac'],

    // Audio to Audio
    'mp3'  => ['wav', 'aac', 'ogg', 'flac'],
    'wav'  => ['mp3', 'aac', 'ogg', 'flac'],
    'aac'  => ['mp3', 'wav', 'ogg', 'flac'],
    'ogg'  => ['mp3', 'wav', 'aac', 'flac'],
    'flac' => ['mp3', 'wav', 'aac', 'ogg'],
    'm4a'  => ['mp3', 'wav', 'aac', 'ogg', 'flac'],
];


// --- Helper Functions ---

/**
 * Sends a consistent JSON response and exits.
 *
 * @param int $code HTTP status code
 * @param string $message Human-readable message
 * @param array $data Success data
 * @param array $errors Error details
 */
function jsonResponse($code, $message, $data = [], $errors = [], $logs = [])
{
    header('Content-Type: application/json');
    http_response_code($code);
    
    echo json_encode([
        'code'    => $code,
        'message' => $message,
        'ts'      => time(),
        'data'    => (object) $data,
        'errors'  => (object) $errors,
        'logs' => $logs,
        'exec_time' => microtime(true) - $start
    ]);
    exit;
}


/**
 * Downloads a file from a URL using cURL with security constraints.
 *
 * @param string $url The URL to download from.
 * @param string $destinationPath The full path to save the file.
 * @param int $maxSize Max file size in bytes (default: 50MB).
 * @return string|true Returns true on success, or an error message string on failure.
 */
function downloadFile($url, $destinationPath, $maxSize = MAX_DOWNLOAD_SIZE)
{
    $fp = fopen($destinationPath, 'w+');
    if ($fp === false) {
        return "Could not open file for writing.";
    }

    $ch = curl_init($url);
    
    // --- SECURITY: Set cURL options ---
    curl_setopt($ch, CURLOPT_TIMEOUT, DOWNLOAD_TIMEOUT); // Use configurable timeout
    curl_setopt($ch, CURLOPT_FILE, $fp); // Write to file
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Follow redirects
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5); // Limit redirects
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // Disable ssl check
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Disable ssl check

    // Set progress function to check file size
    curl_setopt($ch, CURLOPT_NOPROGRESS, false);
    curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function(
        $resource,
        $download_size,
        $downloaded,
        $upload_size,
        $uploaded
    ) use ($maxSize) {
        // $download_size is the total *expected* size.
        // $downloaded is the *current* downloaded size.
        
        // Check current downloaded size
        if ($maxSize > 0 && $downloaded > $maxSize) {
            return -1; // Abort cURL
        }
        // Check expected total size
        if ($maxSize > 0 && $download_size > 0 && $download_size > $maxSize) {
            return -1; // Abort cURL
        }
        return 0; // Continue
    });

    curl_exec($ch);

    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);

    if (!empty($error)) {
        if (file_exists($destinationPath)) {
            unlink($destinationPath); // Delete partial file
        }
        if (strpos($error, 'aborted') !== false) {
             return "File is too large (limit: " . ($maxSize / 1024 / 1024) . "MB).";
        }
        return "cURL Error: " . $error;
    }
    
    if ($httpCode >= 400) {
        if (file_exists($destinationPath)) {
            unlink($destinationPath); // Delete error page (e.g., 404)
        }
        return "Failed to download. Server responded with HTTP code {$httpCode}.";
    }

    return true;
}


// --- Main Logic ---

// 1. Get and Validate Inputs
$url = $_GET['url'] ?? '';
$filename = $_GET['filename'] ?? '';
$toFormat = $_GET['to'] ?? '';

if (empty($toFormat)) {
    jsonResponse(400, "Missing 'to' parameter.", [], ["type" => "Input Error", "details" => "Please provide the target format."], $logs);
}

// **SECURITY: Sanitize inputs**
// Clean the target format
$toFormat = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $toFormat));

$baseFilename = '';
$inputFile = '';

// --- SECURITY WARNING ---
// Allowing downloads from user-provided URLs is a significant security risk,
// including Server-Side Request Forgery (SSRF), Denial of Service (DoS),
// and Remote Code Execution (RCE) if ffmpeg has a vulnerability.
// The code below adds *basic* checks, but a truly secure system
// would use a job queue, whitelisted domains, and full authentication.
// USE THIS IN A PRODUCTION ENVIRONMENT AT YOUR OWN RISK.

if (!empty($url)) {
    // --- 1a. Handle URL Download ---
    
    // **SECURITY: Validate URL**
    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
         jsonResponse(400, "Invalid 'url' parameter.", [], ["type" => "Input Error", "details" => "The provided URL is not valid."], $logs);
    }
    
    // **SECURITY: Validate URL Scheme**
    $scheme = parse_url($url, PHP_URL_SCHEME);
    if (!in_array($scheme, ['http', 'https', 'file'])) {
         jsonResponse(400, "Invalid URL scheme.", [], ["type" => "Input Error", "details" => "Only 'http', 'https', and 'file' URLs are allowed."], $logs);
    }
    
    // Get extension from URL to check *before* downloading
    $originalUrlFilename = basename(parse_url($url, PHP_URL_PATH));
    if (empty($originalUrlFilename)) {
        jsonResponse(400, "Could not determine filename from URL.", [], ["type" => "Input Error", "details" => "URL must end with a filename (e.g., /video.mp4, $logs)."]);
    }
    
    $inputExt = strtolower(pathinfo($originalUrlFilename, PATHINFO_EXTENSION));
    
    // **SECURITY: Check extension against *input* allow-list**
    if (!array_key_exists($inputExt, $allowedConvertFormat)) {
        jsonResponse(400, "Input format '{$inputExt}' from URL is not supported.", [], ["type" => "Validation Error"], $logs);
    }
    
    // **SECURITY: Generate a new, safe, unique filename**
    $baseFilename = uniqid('url_dl_', true) . '.' . $inputExt;
    $inputFile = UPLOADS_DIR . '/' . $baseFilename;

    // Download the file
    $downloadResult = downloadFile($url, $inputFile);
    if ($downloadResult !== true) {
         jsonResponse(500, "Failed to download file from URL.", [], ["type" => "Download Error", "details" => $downloadResult], $logs);
    }

} else if (!empty($filename)) {
    // --- 1b. Handle Local Filename ---
    
    // **SECURITY: Prevent directory traversal**
    $baseFilename = basename($filename);
    if ($baseFilename !== $filename || strpos($filename, '..') !== false) {
         jsonResponse(400, "Invalid 'filename'.", [], ["type" => "Input Error", "details" => "Filename cannot contain path traversal elements (e.g., '..', '/', $logs)."]);
    }
    
    $inputFile = UPLOADS_DIR . '/' . $baseFilename;

    // 2. Check if file exists
    if (!file_exists($inputFile)) {
        jsonResponse(404, "File not found.", [], ["type" => "File Error", "details" => "The file '{$baseFilename}' does not exist in the uploads directory."], $logs);
    }
    
} else {
    jsonResponse(400, "Missing 'filename' or 'url' parameter.", [], ["type" => "Input Error", "details" => "Please provide either a local 'filename' or a 'url' to download."], $logs);
}


// 3. Check if conversion is allowed
$inputExt = strtolower(pathinfo($inputFile, PATHINFO_EXTENSION));

if (!array_key_exists($inputExt, $allowedConvertFormat)) {
    jsonResponse(400, "Input format '{$inputExt}' is not supported.", [], ["type" => "Validation Error"], $logs);
}

if (!in_array($toFormat, $allowedConvertFormat[$inputExt])) {
    jsonResponse(400, "Conversion from '{$inputExt}' to '{$toFormat}' is not allowed.", [], ["type" => "Validation Error"], $logs);
}

// 4. Prepare for Conversion
$outputBasename = pathinfo($baseFilename, PATHINFO_FILENAME);
$outputFilename = $outputBasename . '_' . time() . '.' . $toFormat;
$outputFile = CONVERTED_DIR . '/' . $outputFilename;
$outputUrl = CONVERTED_URL_BASE . '/' . $outputFilename;

// **SECURITY: Escape all shell arguments**
// $escapedFfmpegPath = escapeshellarg($ffmpegPath);
// $escapedInputFile = escapeshellarg($inputFile);
// $escapedOutputFile = escapeshellarg($outputFile);

// Build the FFmpeg command
// -i: input file
// -y: overwrite output file without asking
// We redirect stderr (2>) to stdout (&1) to capture all output
// $command = "{$escapedFfmpegPath} -i {$escapedInputFile} -y {$escapedOutputFile} 2>&1";


// 5. Execute Conversion
try {
    // 5a. Initialize FFMpeg
    $ffmpeg = FFMpeg::create([
        'ffmpeg.binaries'  => $ffmpegPath,
        'ffprobe.binaries' => $ffprobePath,
        'timeout'          => 3600, // 1 hour timeout
        'ffmpeg.threads'   => 12,   // Optional: set number of threads
    ]);

    // 5b. Open the input file
    $video = $ffmpeg->open($inputFile);

    // 5c. Define the target format
    $format = null;
    switch ($toFormat) {
        // ---------------- Video Formats ----------------
        case 'mp4':
        case 'm4v':
        case 'avi':
        case 'mov':
        case 'mkv':
            $format = new Video\X264('libmp3lame', 'libx264');
            // Speed optimized: ultrafast preset, reasonable CRF
            $format->setAdditionalParameters(['-preset', 'ultrafast', '-crf', '28']);
            break;

        case 'wmv':
            $format = new Video\WMV();
            break;

        case 'flv':
            $format = new Video\FLV();
            break;

        case 'webm':
            $format = new Video\WebM();
            break;

        // ---------------- Audio Formats ----------------
        case 'mp3':
            $format = new Audio\Mp3();
            break;

        case 'wav':
            $format = new Audio\Wav();
            break;

        case 'aac':
            $format = new Audio\Aac();
            break;

        case 'ogg':
            // Note: Audio Ogg (libvorbis)
            $format = new Audio\Ogg();
            break;

        case 'flac':
            $format = new Audio\Flac();
            break;

        default:
            jsonResponse(
                500,
                "Internal error: No format class available for '{$toFormat}'.",
                [],
                ["type" => "Server Error"],
                $logs
            );
    }

    // Optional: Add a progress listener (useful for debugging, or real-time updates with a job queue)
    
    $format->on('progress', function ($video, $format, $percentage) {
        file_put_contents(CONVERTED_DIR . '/progress.txt', $percentage . '%');
    });

    // 5d. Limit bitrate and save the file
    $format->setKiloBitrate(350);
    $video->save($format, $outputFile);
    
    // 6. Handle Response
    // Success
    jsonResponse(200, "File converted successfully.", [
        "original_file" => $baseFilename,
        "new_file"      => $outputFilename,
        "file_url"      => $outputUrl
    ]);

} catch (\Exception $e) {
    // Failure
    jsonResponse(500, "File conversion failed.", [], [
        "type"    => "Conversion Error",
        "details" => "PHP-FFMpeg library error.",
        "log"     => $e->getMessage() // Provide the exception message
    ]);
}