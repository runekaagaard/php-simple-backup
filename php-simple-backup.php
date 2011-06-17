#! /usr/bin/env php
<?php

// Errors on.
error_reporting(E_ALL|E_STRICT);
ini_set('display_errors', 1);
define('DEBUG', false);

function error($message, $status=1) {
    echo "\033[31m$message\033[0m\n";
    exit($status);
}

function ok($message) {
    echo "\033[32m$message\033[0m\n";
}

function help() {
    echo 
"=================
php-simple-backup
=================

php-simple-backup is a super simple MySQL backup script with hourly, daily, 
weekly, monthly and yearly rotation.

== Usage example ==
    ./php-simple-backup.php \
        --keep=0,7,4,12,-1 \
        --connection=localhost,username,password \
        --backup-dir=/media/bck/mysql \
        --exclude=mysql,information_schema
        --default-character-set=utf8

== Required options ==
   --keep: Number of hourly, daily, weekly, monthly and yearly backups to keep.
           A value of `0` means that no backups will be made for that interval
           and a value of `-1` means that a infinite amount will be kept.
   --connection: The host, username and password of the mysql server to backup.
   --backup-dir: Where to place the backup files.

== Optional options ==
    --exclude: The databases to exclude.
    --help: Shows this help.
    
== Other options ==
All other options will be passed to mysqldump. You probably want to pass 
'--default-character-set=utf8'.

";
    exit(1);
}

function parse_options() {
    global $argv;
    $avail_opts = array(
        '--connection' => true, 
        '--keep' => true, 
        '--backup-dir' => true, 
        '--exclude' => false
    );
    $args = $argv;
    array_shift($args);
    if (empty($args[0]) || strpos(implode(" ", $argv), '--help') !== false) 
        help();
    $opts = array('extra' => '');
    foreach ($args as $arg) {
       $parts = explode('=', $arg);
       if (count($parts) !== 2) {
           $opts['extra'] .= ' ' . $arg;
           continue;
       }
       list($opt, $value) = $parts;
       if (isset($avail_opts[$opt])) {
            $opt = ltrim($opt, '-');
            $func_name = "parse_" . str_replace('-', '_', $opt);
            $opts[$opt] = $func_name($value); 
       } else {
           $opts['extra'] .= ' ' . $arg;
       }
    }
    foreach ($avail_opts as $opt => $required)
        if ($required && !isset($opts[ltrim($opt, '-')]))
            error("Missing required option: $opt.");
    $opts['tmp-dir'] = sys_get_temp_dir();
    $opts += array('exclude' => array());
    return $opts;
}

function parse_exclude($value) {
    return explode(',', $value);
}

function parse_keep($value) {
    $parts = explode(',', $value);
    if (count($parts) !== 5) error('--keep: Must consist of 5 digits.');
    $names = array('hourly', 'daily', 'weekly', 'monthly', 'yearly');
    $opts = array();
    foreach ($parts as $part) {
        if ($part !== '-1' && !ctype_digit($part)) 
            error("--keep: Part '$part' must be a digit.");
        if ($part !== "0") $opts[array_shift($names)] = (int)$part;
    }
    return $opts;
}

function parse_backup_dir($value) {
    if (!is_dir($value)) error("--backup-dir: The directory $value does not exist.");
    return $value;
}

function parse_connection($value) {
    $parts = explode(',', $value);
    if (count($parts) !== 3) error('--keep: Must consist of 3 parts.');
    return "-h$parts[0] -u$parts[1] -p$parts[2]";
}

function run($cmd, $allow_error=false) {
    $cmd = "nice -n 19 $cmd";
    if (DEBUG) echo "Running the command: '$cmd' ... ";
    exec($cmd, $output, $status);
    if (!$allow_error && $status !== 0) {
        error("Failed running command: $cmd", $status);
    } else {
        if (DEBUG) ok("OK!\n");
    }
    return $output;
}

function create_dirs($opts) {
    if (!is_writable($opts['backup-dir'])) 
        error("--backup-dir: Is not writeable.");
    foreach ($opts['keep'] as $k => $v) {
        if ($v === 0) continue;
        $dir = $opts['backup-dir'] . "/$k";
        $exists = is_dir($dir);
        if (!$exists && !mkdir($dir)) 
            error("--backup-dir: $dir is not writeable.");
        if (!$exists) ok("Created directory: $dir");
    }
}

function main() {
    $opts = parse_options();
    create_dirs($opts);
    $dbs = run('mysql ' . $opts['connection'] . ' -e "show databases" -B -N');
    $seconds = array(
        'hourly' =>  60 * 60, 
        'daily' =>   60 * 60 * 24, 
        'weekly' =>  60 * 60 * 24 * 7, 
        'monthly' => 60 * 60 * 24 * 30, 
        'yearly' =>  60 * 60 * 24 * 365.25,
    );
    $time = time();
    foreach ($dbs as $db) {
        $db = trim($db);
        if (array_search($db, $opts['exclude'])) continue;
        $old_file = false;
        foreach ($opts['keep'] as $name => $keep) {
            $dir = "{$opts['backup-dir']}/$name";
            $minutes = $seconds[$name];
            $files = run("ls -t $dir | grep '.$db.tar.gz'", true);
            if ($keep === -1
                || empty($files) 
                || $time - filemtime("$dir/$files[0]") >= $seconds[$name]) 
            {
                $file = "$dir/"
                        .  strftime('%Y_%m_%d_%H:%M:%S'). "__{$db}.tar.gz";
                if (empty($old_file)) {
                    run("mysqldump {$opts['extra']} {$opts['connection']} $db | gzip -c > $file");
                    $old_file = $file;
                } else {
                    run("cp $old_file $file");
                    $file = $old_file;
                }
                ok("Created $name backup $file");
                if ($keep !== -1 && count($files) > $keep) {
                    run("rm $dir/" . array_pop($files));
                }
            }
            
        }
    }
    exit(0);
}
main();