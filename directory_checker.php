<?php
function bootDirs() {
    $directories = ['uploads', 'converted'];
    $logs = [];
    foreach ($directories as $dir) {
        // Check if directory exists
        if (!is_dir($dir)) {
            $dir_tmp = $dir;
            if (mkdir($dir, 0755, true)) {
                $logs["log"] = "Directory '{$dir_tmp}' created successfully.";
            } else {
                $logs["error"] = "Failed to create directory '{$dir}'.\n";
                continue;
            }
        } else {
            $logs["log"] = "Directory '{$dir}' already exists.\n";
        }

        // Set permissions
        if (chmod($dir, 0755)) {
            $logs["log"] = "Permissions for '{$dir}' set to 755.\n";
        } else {
            $logs["error"] = "Failed to set permissions for '{$dir}'.\n";
        }
    }

    return $logs;
}

