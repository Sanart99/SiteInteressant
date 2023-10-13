<?php
namespace LDLib\AWS;

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/utils/utils.php';
dotenv();

use Aws\S3\S3Client;
use LDLib\General\{OperationResult, ErrorType, SuccessType};
use LDLib\Database\LDPDO;
use LDLib\User\RegisteredUser;

class AWS {
    public static ?LDS3Client $client;
    public static bool $initialized = false;

    public static function init() {
        if (self::$initialized) return;
        self::$initialized = true;
        try {
            self::$client = new LDS3Client(new S3Client([
                'region'      => $_SERVER['LD_AWS_REGION'],
                'version'     => '2006-03-01',
                'credentials' => new \Aws\Credentials\Credentials($_SERVER['LD_AWS_ACCESS_KEY'],$_SERVER['LD_AWS_SECRET_KEY'])
            ]));
        } catch (\Exception $e) {
            self::$client = null;
        }
    }

    public static function getS3Client():?LDS3Client {
        if (!self::$initialized) self::init();
        return self::$client;
    }
}

class LDS3Client {
    public function __construct(public ?S3Client $client) { }

    public function getObject(string $bucketName, string $key) {
        try {
            return $this->client->getObject([
                'Bucket' => $bucketName,
                'Key' => $key
            ]);
        } catch (\Aws\Exception\AwsException $e) {
            return $e;
        }
    }

    public function putObject(LDPDO $conn, RegisteredUser $user, array $file, bool $overwrite=false, bool $newNameOnDuplicate=false):OperationResult {
        $fileName = $file['name'];
        $fileData = file_get_contents($file['tmp_name']);
        $fileSize = $file['size'];
        $mimeType = mime_content_type($file['tmp_name']);
        $keyName = "{$user->id}_{$fileName}";
        $bucketName = $_SERVER['LD_AWS_BUCKET_GENERAL'];
        if ($mimeType === false) $mimeType = null;

        $s3 = AWS::getS3Client();
        if ($s3 == null) return new OperationResult(ErrorType::AWS_ERROR, "Couldn't connect to bucket.");

        if ($overwrite !== true && $s3->client->doesObjectExistV2($bucketName,$keyName)) {
            if ($newNameOnDuplicate) {
                $keyName = "{$user->id}_".time()."_$fileName";
            } else return new OperationResult(ErrorType::PROHIBITED, "File already exists.");
        }

        $stmt = $conn->prepare("INSERT IGNORE INTO s3_general (user_id,obj_key,filename,size,mime_type) VALUES (?,?,?,?,?)");
        if (!$stmt->execute([$user->id,$keyName,$fileName,$fileSize,$mimeType])) return new OperationResult(ErrorType::DATABASE_ERROR);

        try {
            $res = $s3->client->putObject([
                'Bucket' => $bucketName,
                'Key' => $keyName,
                'Body' => $fileData,
                'ChecksumAlgorithm' => 'SHA256',
                'ContentType' => $mimeType,
                'Metadata' => [
                    'userId' => $user->id,
                    'username' => $user->username
                ]
            ]);
        } catch (\Aws\Exception\AwsException $e) {
            $stmt = $conn->prepare("DELETE FROM s3_general WHERE user_id=? AND obj_key=?");
            $stmt->execute([$user->id,$keyName]);
            return new OperationResult(ErrorType::AWS_ERROR, "Couldn't put object. (Permissions?)");
        }

        if (($res['@metadata']['statusCode']??null) != 200) {
            $stmt = $conn->prepare("DELETE FROM s3_general WHERE user_id=? AND obj_key=?");
            $stmt->execute([$user->id,$keyName]);
            return new OperationResult(ErrorType::AWS_ERROR, "Bad AWS status code.");
        }
        
        return new OperationResult(SuccessType::SUCCESS, null, [], [$keyName]);
    }
}
?>