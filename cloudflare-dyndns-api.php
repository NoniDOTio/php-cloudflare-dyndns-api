<?php

//
// Settings
//
const USE_CACHE = true;
const REQUIRE_AUTH = true;
const TURN_ON_LOGGING = true;
const LOGLEVEL = 'INFO';
const AUTH_USERNAME = 'sunshine';
const AUTH_PASSWORD = 'abc123';


//
// Get variables from query string
// You may fill "" with default values and omit the fields in requests to this script.
//
define("USERNAME", $_GET["user"] ?? "");
define("PASSWORD", $_GET["pass"] ?? "");
define("IPV4_ADDRESS", $_GET["ipv4"] ?? "");
define("IPV6_ADDRESS", $_GET["ipv6"] ?? "");
define("DOMAIN", $_GET["domain"] ?? "");
define("CLOUDFLARE_API_KEY", $_GET["cfapikey"] ?? "");
define("CLOUDFLARE_EMAIL", $_GET["cfemail"] ?? "");


//
// Thank god they finally added enums to PHP
//
enum IPVersion {
    case v4;
    case v6;

    function type(): string {
        return match($this)
        {
            self::v4 => 'A',
            self::v6 => 'AAAA',
        };
    }

    function name(): string {
        return match($this)
        {
            self::v4 => 'IPv4',
            self::v6 => 'IPv6',
        };
    }
}


enum LogLevel : int {
    case DEBUG = 0;
    case INFO = 1;
    case WARN = 2;
    case ERROR = 3;

    static function fromString(string $str): self {
        return match(strtoupper($str)) {
            'DEBUG' => self::DEBUG,
            'WARN' => self::WARN,
            'ERROR' => self::ERROR,
            default => self::INFO,
        };
    }
}


//
// Log to file
//
function write_log(string $message, $level = LogLevel::INFO) : void {
    if(!TURN_ON_LOGGING) return;
    if($level < LogLevel::fromString(LOGLEVEL)) return;
    $log_file_name = CLOUDFLARE_RECORD_NAME . "." . CLOUDFLARE_DOMAIN . date('_Y-m-d') . ".log";
    file_put_contents($log_file_name , date('[Y-m-d H:i:s] ') . $message . PHP_EOL, FILE_APPEND);
}


//
// Compare IP to cached IP
//
function ip_is_equal_to_cached_ip(string $ip, IPVersion $ip_version) : bool {
    if(!USE_CACHE) return false;

    $cache_file_name = CLOUDFLARE_RECORD_NAME . "." . CLOUDFLARE_DOMAIN . "." . $ip_version->name() . ".cache";
    if (!file_exists($cache_file_name)) {
        file_put_contents($cache_file_name, "");
    }
    return $ip === file_get_contents($cache_file_name);
}


//
// Update Cache with new IP
//
function update_cache(string $ip_address, IPVersion $ip_version) : void {
    if(!USE_CACHE) return;

    $cache_file_name = CLOUDFLARE_RECORD_NAME . "." . CLOUDFLARE_DOMAIN . "." . $ip_version->name() . ".cache";
    file_put_contents($cache_file_name, $ip_address);
}


//
// Get Cloudflare Zone ID
//
function cloudflare_get_zone_id() : string {
    $ch = curl_init("https://api.cloudflare.com/client/v4/zones" . '?name=' . CLOUDFLARE_DOMAIN);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'X-Auth-Email: ' . CLOUDFLARE_EMAIL,
        'X-Auth-Key: ' . CLOUDFLARE_API_KEY,
        'Content-Type: application/json',
    ));

    $response = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (!isset($response['result'][0]['id'])) {
        write_log('Error fetching zone ID from Cloudflare API', LogLevel::ERROR);
        header("HTTP/1.1 500 Internal Server Error");
        die('Error fetching zone ID from Cloudflare API');
    }

    return $response['result'][0]['id'];
}


//
// Fetch a Cloudflare record ID
//
function cloudflare_get_record_id(string $cloudflare_zone_id, IPVersion $ip_version) : string {
    $type = match($ip_version) {
        IPVersion::v4 => "A",
        IPVersion::v6 => "AAAA",
    };
    $ch = curl_init("https://api.cloudflare.com/client/v4/zones" . '/' . $cloudflare_zone_id . '/dns_records?type=' . $type . '&name=' . CLOUDFLARE_RECORD_NAME . '.' . CLOUDFLARE_DOMAIN);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'X-Auth-Email: ' . CLOUDFLARE_EMAIL,
        'X-Auth-Key: ' . CLOUDFLARE_API_KEY,
        'Content-Type: application/json',
    ));

    $response = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (!isset($response['result'][0]['id'])) {
        write_log('Error fetching record ID from Cloudflare API', LogLevel::ERROR);
        header("HTTP/1.1 500 Internal Server Error");
        die('Error fetching record ID from Cloudflare API');
    }

    return $response['result'][0]['id'];
}


//
// Update the DNS record with IPv4 address
//
function cloudflare_updated_record(string $cloudflare_zone_id, string $cloudflare_record_id, string $ip_address, IPVersion $ip_version) : void {
    $ch = curl_init("https://api.cloudflare.com/client/v4/zones" . '/' . $cloudflare_zone_id . '/dns_records/' . $cloudflare_record_id);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'X-Auth-Email: ' . CLOUDFLARE_EMAIL,
        'X-Auth-Key: ' . CLOUDFLARE_API_KEY,
        'Content-Type: application/json',
    ));

    $data = json_encode(array(
        'type' => $ip_version->type(),
        'name' => CLOUDFLARE_RECORD_NAME . '.' . CLOUDFLARE_DOMAIN,
        'content' => $ip_address,
    ));

    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    $response = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (isset($response['success']) && $response['success']) {
        write_log('Set ' . $ip_version->type() . ' ' . CLOUDFLARE_RECORD_NAME . ' to ' . $ip_address);
    } else {
        write_log('Error updating ' . CLOUDFLARE_RECORD_NAME . ' with IPv4 address: ' . $response['errors'][0]['message'], LogLevel::ERROR);
        header("HTTP/1.1 500 Internal Server Error");
        die('Error updating Cloudflare record');
    }
}


function update_ip_address(string $ip_address, IPVersion $ip_version) : void {
    if(ip_is_equal_to_cached_ip($ip_address, $ip_version)) {
        write_log(
            message: $ip_version->name() . ' for ' . CLOUDFLARE_RECORD_NAME . ' (' . $ip_address . ') is already cached.',
            level: LogLevel::DEBUG,
        );
    } else {
        update_cache(
            ip_address: $ip_address,
            ip_version: $ip_version,
        );
        $cloudflare_zone_id = cloudflare_get_zone_id();
        $cloudflare_record_id = cloudflare_get_record_id(
            cloudflare_zone_id: $cloudflare_zone_id,
            ip_version: $ip_version,
        );
        cloudflare_updated_record(
            cloudflare_zone_id: $cloudflare_zone_id,
            cloudflare_record_id: $cloudflare_record_id,
            ip_address: $ip_address,
            ip_version: $ip_version,
        );
    }
}


//
// Verify username and password is correct
//
if(REQUIRE_AUTH && USERNAME !== AUTH_USERNAME && PASSWORD !== AUTH_PASSWORD) {
    header("HTTP/1.1 401 Unauthorized");
    die("HTTP 401 Unauthorized: Access Denied");
}


//
// Split into subdomain and domain
//
$domain_parts = explode(".", DOMAIN);
if(count($domain_parts) < 2) {
    header("HTTP/1.1 400 Bad Request");
    die("HTTP 400 Bad Request: Invalid Domain");
}
$record_name = implode(".", array_slice($domain_parts, 0, -2));
define("CLOUDFLARE_RECORD_NAME", $record_name === "" ? "@" : $record_name);
define("CLOUDFLARE_DOMAIN", $domain_parts[count($domain_parts) - 2] . "." . end($domain_parts));


//
// Update IPv4 and IPv6
//
if(IPV4_ADDRESS !== "") {
    update_ip_address(IPV4_ADDRESS, IPVersion::v4);
}
if(IPV6_ADDRESS !== "") {
    update_ip_address(IPV6_ADDRESS, IPVersion::v6);
}
