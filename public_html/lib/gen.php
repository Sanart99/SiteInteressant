<?php
namespace LDLib\General;

require_once __DIR__.'/../subdomains/api/schema.php';
require_once __DIR__.'/db.php';

class PaginationVals {
    public readonly ?int $first;
    public readonly ?int $last;
    public ?string $sortBy = null;
    public bool $requestPageCount;
    public bool $lastPageSpecialBehavior;
    public int $skipPages = 0;
    private ?string $after;
    private ?string $before;
    private string $s;

    public function __construct(?int $first, ?int $last, ?string $after, ?string $before, bool $requestPageCount=false, bool $lastPageSpecialBehavior=false) {
        if (($first == null || $first < 0) && ($last == null || $last < 0)) throw new \InvalidArgumentException("Invalid arguments.");
        $this->first = $first;
        $this->last = $last;
        $this->after = $after;
        $this->before = $before;
        $this->requestPageCount = $requestPageCount;
        $this->lastPageSpecialBehavior = $lastPageSpecialBehavior;
        $this->s = self::asString($this->first,$this->last,$this->after,$this->before,$this->sortBy);
    }

    public function setAfterCursor(string $after) {
        $this->after = $after;
        $this->s = self::asString($this->first,$this->last,$this->after,$this->before,$this->sortBy);
    }

    public function setBeforeCursor(string $before) {
        $this->before = $before;
        $this->s = self::asString($this->first,$this->last,$this->after,$this->before,$this->sortBy);
    }

    public function getAfterCursor() { return $this->after; }
    public function getBeforeCursor() { return $this->before; }
    public function getString() { return $this->s; }

    public static function asString(?int $first, ?int $last, ?string $after, ?string $before, ?string $sortBy):string {
        $s = '';
        if ($first != null && $first > 0) $s .= "f-{$first}";
        else if ($last != null && $last > 0) $s .= "l-{$last}";
        else throw new \InvalidArgumentException("Invalid arguments.");
        
        if ($after != null) $s .= "-a-{$after}";
        else if ($before != null) $s .= "-b-{$before}";

        if ($sortBy!=null) $s .= "-sort:$sortBy";

        return $s;
    }
}

class PageInfo {
    public readonly ?string $startCursor;
    public readonly ?string $endCursor;
    public readonly bool $hasPreviousPage;
    public readonly bool $hasNextPage;
    public readonly ?int $pageCount;
    public readonly ?int $currPage;

    public function __construct(?string $startCursor, ?string $endCursor, bool $hasPreviousPage, bool $hasNextPage, ?int $pageCount, ?int $currPage) {
        $this->startCursor = $startCursor;
        $this->endCursor = $endCursor;
        $this->hasPreviousPage = $hasPreviousPage;
        $this->hasNextPage = $hasNextPage;
        $this->pageCount = $pageCount;
        $this->currPage = $currPage;
    }
}

enum SuccessType {
    case SUCCESS;
    case PARTIAL_SUCCESS;
}

enum ErrorType {
    case AWS_ERROR;
    case CONTEXT_INVALID;
    case DATABASE_ERROR;
    case DBLOCK_TAKEN;
    case DUPLICATE;
    case EXPIRED;
    case FILE_OPERATION_ERROR;
    case INVALID;
    case INVALID_DATA;
    case LIMIT_REACHED;
    case NOT_AUTHENTICATED;
    case NOT_ENOUGH_PRIVILEGES;
    case NOT_FOUND;
    case PROHIBITED;
    case USELESS;
    
    case UNKNOWN;
}

class TypedException extends \Exception implements \GraphQL\Error\ClientAware {
    private ErrorType $errorType;
    
    public function __construct(string $message, ErrorType $errorType, $code=0, \Throwable $previous = null) {
        $this->errorType = $errorType;
        parent::__construct($message, $code, $previous);
    }

    public function getErrorType():ErrorType { return $this->errorType; }

    public function isClientSafe():bool { return true; }
}

class OperationResult {
    public string $resultMsg = '';

    public function __construct(public SuccessType|ErrorType $resultType, ?string $resultMsg = null, public array $fieldsData = [], public array $data = []) {
        if ($resultMsg != null) $this->resultMsg = $resultMsg;
        else switch ($resultType) {
            case ErrorType::INVALID_DATA: $this->resultMsg = 'Invalid data.'; break;
            case ErrorType::NOT_AUTHENTICATED: $this->resultMsg = 'User not authenticated.'; break;
            case ErrorType::NOT_ENOUGH_PRIVILEGES: $this->resultMsg = 'User not authorized.'; break;
            default: $this->resultMsg = $resultType instanceof ErrorType ? 'Something went wrong.' : 'No problem detected.'; break;
        }
    }
}
?>