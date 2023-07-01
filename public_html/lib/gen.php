<?php
namespace LDLib\General;

require_once __DIR__.'/../subdomains/api/schema.php';
require_once __DIR__.'/db.php';

class PaginationVals {
    public readonly ?int $first;
    public readonly ?int $last;
    public ?string $sortBy = null;
    private ?string $after;
    private ?string $before;
    private string $s;

    public function __construct(?int $first, ?int $last, ?string $after, ?string $before) {
        if (($first == null || $first < 0) && ($last == null || $last < 0)) throw new \InvalidArgumentException("Invalid arguments.");
        $this->first = $first;
        $this->last = $last;
        $this->after = $after;
        $this->before = $before;
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

    public function __construct(?string $startCursor, ?string $endCursor, bool $hasPreviousPage, bool $hasNextPage) {
        $this->startCursor = $startCursor;
        $this->endCursor = $endCursor;
        $this->hasPreviousPage = $hasPreviousPage;
        $this->hasNextPage = $hasNextPage;
    }
}

enum ErrorType {
    case USER_INVALID;
    case USERNAME_INVALID;
    case PASSWORD_INVALID;
    case EMAIL_INVALID;
    case EMAIL_DUPLICATE;

    case INVITECODE_INVALID;
    case INVITECODE_EXPIRED;
    case INVITECODE_LIMITREACHED;
    case INVITECODE_NOTFOUND;
    case INVITECODE_ALREADYPROCESSED;

    case TITLE_TOOLONG;
    case MESSAGE_TOOSHORT;
    case MESSAGE_TOOLONG;
    case TAG_INVALID;

    case DBLOCK_TAKEN;

    case PROHIBITED;
    case DATABASE_ERROR;
    case OPERATION_UNAUTHORIZED;
    case DUPLICATE;
    case NOTFOUND;
    case EXPIRED;
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
?>