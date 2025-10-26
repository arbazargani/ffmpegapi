FFmpeg PHP Conversion Script

This script provides a basic API for converting media files using FFmpeg.

IMPORTANT SECURITY NOTICE:

This script is designed to process files already on your server in a dedicated uploads directory. It does NOT download files from external URLs, as requested in the original prompt. Allowing downloads from arbitrary user-supplied URLs is a major security risk (SSRF, RCE, DoS) and is strongly discouraged.

Setup

Create Directories:
You must create two directories in the same folder as the PHP script. Make sure your web server (e.g., Apache, Nginx) has permission to write to them.

composer require php-ffmpeg/php-ffmpeg
mkdir uploads
mkdir converted
chmod -R 755 uploads
chmod -R 755 converted


uploads/: Place the files you want to convert here.

converted/: The script will save converted files here.

Create .env File:
Copy the .env.example file to a new file named .env and fill in the paths to your FFmpeg binaries.

cp .env.example .env


Then, edit .env with the correct paths for your system.

Install FFmpeg:
You must have FFmpeg installed on your server. You can download it from https://ffmpeg.org/.

How to Use

Once set up, you can call the script with a filename (which must exist in the uploads/ directory) and a to format.

Example Request:

http://your-server.com/format_factory.php?filename=my-video.avi&to=mp4

Example Success Response:

{
    "code": 200,
    "message": "File converted successfully.",
    "ts": 1678886400,
    "data": {
        "original_file": "my-video.avi",
        "new_file": "my-video_1678886400.mp4",
        "file_url": "/converted/my-video_1678886400.mp4"
    },
    "errors": {}
}


Example Error Response:

{
    "code": 400,
    "message": "Conversion from 'mp3' to 'mp4' is not allowed.",
    "ts": 1678886401,
    "data": {},
    "errors": {
        "type": "Validation Error"
    }
}
