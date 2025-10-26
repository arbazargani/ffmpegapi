# FFmpeg PHP Conversion Script

This script provides a basic API for converting media files using FFmpeg.

---

## ⚠️ Important Security Notice

This script is designed to process files **already on your server** in a dedicated `uploads` directory.
It **does NOT download files from external URLs**, as that poses a major security risk (SSRF, RCE, DoS). Allowing downloads from arbitrary user-supplied URLs is **strongly discouraged**.

---

## Setup

### 1. Install Required Composer Packages

```bash
composer install --ignore-platform-reqs
```

### 2. Set NGINX Timeouts

Add the following to your NGINX configuration:

```nginx
server {
    ...

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;

        # Timeouts
        fastcgi_read_timeout 120s;
        fastcgi_send_timeout 120s;
    }
}
```

### 3. Create Directories

You must create two directories in the same folder as the PHP script and ensure your web server has write permissions:

```bash
mkdir uploads
mkdir converted
chmod -R 755 uploads
chmod -R 755 converted
```

* **`uploads/`**: Place the files you want to convert here.
* **`converted/`**: Converted files will be saved here.

### 4. Create `.env` File

Copy the example environment file and edit it with the correct paths to your FFmpeg binaries:

```bash
cp .env.example .env
```

Edit `.env` to match your system configuration.

### 5. Install FFmpeg

Make sure FFmpeg is installed on your server. You can download it from [https://ffmpeg.org/](https://ffmpeg.org/).

---

## How to Use

Once set up, call the script with a filename (must exist in `uploads/`) and a target format.

### Example Request

```
http://your-server.com/format_factory.php?filename=my-video.avi&to=mp4
```

### Example Success Response

```json
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
```

### Example Error Response

```json
{
    "code": 400,
    "message": "Conversion from 'mp3' to 'mp4' is not allowed.",
    "ts": 1678886401,
    "data": {},
    "errors": {
        "type": "Validation Error"
    }
}
```
