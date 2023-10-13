<?php
$libDir = __DIR__.'/../../lib';
require_once $libDir.'/gen.php';
require_once $libDir.'/utils/utils.php';
require_once __DIR__.'/../../vendor/autoload.php';
require_once __DIR__.'/schema.php';
dotenv();

use GraphQL\GraphQL;
use GraphQL\Error\{ClientAware,DebugFlag,ProvidesExtensions,Error};
use GraphQL\Executor\Promise\Adapter\ReactPromiseAdapter;
use GraphQL\Executor\ExecutionResult;
use GraphQL\Language\SourceLocation;
use GraphQL\Type\Schema;
use LDLib\General\TypedException;
use Schema\{Context,Cache,Types};

header("Access-Control-Allow-Origin: {$_SERVER['LD_LINK_ROOT']}");
header('Access-Control-Allow-Headers: Cache-Control, Content-Type');
header('Access-Control-Allow-Credentials: true');

// Get query
$rawInput = file_get_contents('php://input');
if (!empty($rawInput)) $input = json_decode($rawInput, true);
else if (isset($_POST['gqlQuery'])) $input = json_decode($_POST['gqlQuery'], true);
else { http_response_code(200); echo "..."; return; }

if ($input == null) { http_response_code(400); echo "JSON ERROR."; return; }
else if (!is_array($input) || !array_key_exists('query',$input)) { http_response_code(400); echo "Bad request : $input"; return;  }

// Get query variables
$rawVariables = null;
if (isset($input['variables'])) $rawVariables = $input['variables'];
else if (isset($_POST['gqlVariables'])) $rawVariables = $_POST['gqlVariables'];
$queryVariables = null;
if (!empty($rawVariables)) {
    if (is_array($rawVariables)) $queryVariables = $rawVariables;
    else $queryVariables = json_decode($rawVariables,true, 512, JSON_THROW_ON_ERROR);
}


$operationName = (isset($input['operationName']) && is_string($input['operationName'])) ? $input['operationName'] : null;

Context::init();

$isDebug = (bool)$_SERVER['LD_DEBUG'];

$schema = new Schema([
    'query' => Types::Query(),
    'mutation' => Types::Mutation(),
    'typeLoader' => fn(string $name) => Types::$name(),
    'types' => [Types::BasicNotification(), Types::ForumNotification()]
]);
$defaultResolver = function():mixed { return null; };
$errorFormatter = function(Error $err) {
    if ($err instanceof ClientAware && $err->isClientSafe()) {
        $err2 = $err->getPrevious();
        switch (true) {
            case $err2 instanceof TypedException:
                $type = (fn($v):TypedException=>$v)($err2)->getErrorType()->name; break; // weird thing is for removing intelephense error mark
            case $err2 == null:
                $type = "GENERAL"; break;
            default:
                $type = "UNKNOWN"; break;
        }
        $formattedError = [
            'type' => $type,
            'message' => $err->getMessage()
        ];
    } else $formattedError = ['message' => "Internal server error."];

    if ($err instanceof Error) {
        $locations = array_map(static fn (SourceLocation $loc): array => $loc->toSerializableArray(), $err->getLocations());
        if ($locations !== []) $formattedError['locations'] = $locations;

        if ($err->path !== null && \count($err->path) > 0) $formattedError['path'] = $err->path;
    }

    if ($err instanceof ProvidesExtensions) {
        $extensions = $err->getExtensions();
        if (\is_array($extensions) && $extensions !== []) {
            $formattedError['extensions'] = $extensions;
        }
    }

    return $formattedError;
};
$errorHandler = function (array $errors, callable $formatter) { return array_map($formatter,$errors); };

try {
    $promise = GraphQL::promiseToExecute(new ReactPromiseAdapter(), $schema, $input['query'], null, Context::$a, $queryVariables, $operationName, $defaultResolver);
    $promise->then(function(ExecutionResult $result) use(&$output, &$isDebug, $errorFormatter, $errorHandler) {
        $output = $result->setErrorFormatter($errorFormatter)->setErrorsHandler($errorHandler)->toArray($isDebug ? DebugFlag::INCLUDE_DEBUG_MESSAGE : (DebugFlag::NONE));
        if ($isDebug) {
            if (count(Context::$logs) > 0) {
                $output['logs'] = [];
                $i = 0;
                foreach (Context::$logs as $err) $output['logs'][$i++] = $err;
            }
            
            $output['cost'] = Context::$cost/100;

            if (Cache::$setCount > 0 || Cache::$getCount > 0) {
                $output['cache'] = [
                    'get' => Cache::$getCount,
                    'set' => Cache::$setCount,
                ];
                if (Cache::$keysNotFound > 0) $output['cache']['keysNotFound'] = Cache::$keysNotFound;
            }
        }
    });
} catch (\Exception $e) {
    $output = [ 'errors' => [$errorFormatter($e)]];
    http_response_code(500);
}

header('Content-Type: application/json');
echo json_encode($output, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
?>