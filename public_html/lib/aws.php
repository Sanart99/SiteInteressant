<?php
namespace LDLib\AWS;

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/utils/utils.php';
dotenv();

use Aws\S3\S3Client;

class AWS {
    public static ?S3Client $client;
    public static bool $initialized = false;

    public static function init() {
        if (self::$initialized) return;
        self::$initialized = true;
        try {
            self::$client = new S3Client([
                'region'      => $_SERVER['LD_AWS_REGION'],
                'version'     => '2006-03-01',
                'credentials' => new \Aws\Credentials\Credentials($_SERVER['LD_AWS_ACCESS_KEY'],$_SERVER['LD_AWS_SECRET_KEY'])
            ]);
        } catch (\Exception $e) {
            self::$client = null;
        }
    }

    public static function getS3Client():?S3Client {
        if (!self::$initialized) self::init();
        return self::$client;
    }
}
?>