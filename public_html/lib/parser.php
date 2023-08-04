<?php
namespace LDLib\Parser;

use Ds\Set;
use Schema\UsersBuffer;

$urlRegex = '/(^(https?|ftp):\/\/(\S*?\.\S*?))([\s)\[\]{},;"\':<]|\.\s|$)/i';

class SymbolMarker  {
    public static array $all = [];
    public string $from;
    public string $to;

    public ?int $iA = null;

    private string $result;

    public function __construct(string $from, string $to, string &$result) {
        $this->from = $from;
        $this->{'to'} = $to;
        $this->result =& $result;
        self::$all[] = $this;
    }

    public function mark(int $i) {
        $result =& $this->result;
        $from =& $this->from;
        $to =& $this->to;
        $iA = $this->iA;
        if ($iA === null) { $this->iA = $i; $result .= $from; return; }
        
        $before = substr($result,0,$iA);
        $insert1 = "<$to>";
        $middle = substr($result,$iA+strlen($from),$i);
        $insert2 = "</$to>";
        $end = substr($result,$i);
        $this->result = $before . $insert1 . $middle . $insert2 . $end;
        $this->iA = null;
        foreach (SymbolMarker::$all as $o) if ($o != $this && $o->iA != null && $o->iA > $iA) { $o->iA += strlen($insert1)-strlen($from); }
        foreach (DoubleMarker::$all as $o) foreach ($o->arrA as &$a) if ($a[0] > $iA) { $a[0] += strlen($insert1)-strlen($from); }
    }
}

class DoubleMarker {
    public static array $all = [];
    public string $from;

    public array $arrA = [];

    private string $result;

    public function __construct(string $from, callable $to, string &$result) {
        $this->from = $from;
        $this->{'to'} = $to;
        $this->result =& $result;
        self::$all[] = $this;
    }

    public function markA(int $i, ?string $arg=null) {
        $this->arrA[] = [$i,$arg];
    }

    public function markB(int $iB) {
        $result =& $this->result;
        $from =& $this->from;

        $v = array_pop($this->arrA);
        if ($v === null) { $result .= "[/$from]"; return; }
        $iA = $v[0];
        $arg = $v[1];
        $to = call_user_func($this->{'to'},$arg);
        $lenBefore1 = strlen($arg == null ? "[$from]" : "[{$from}={$arg}]");

        $before = substr($result,0,$iA);
        $insert1 = $to[0];
        $middle = substr($result,$iA+$lenBefore1,$iB);
        $insert2 = $to[1];
        $end = substr($result,$iB);
        $this->result = $before . $insert1 . $middle . $insert2 . $end;
        foreach (SymbolMarker::$all as $o) if ($o != $this && $o->iA != null && $o->iA > $iA) { $o->iA += strlen($insert1)-$lenBefore1; }
        foreach (DoubleMarker::$all as $o) foreach ($o->arrA as &$a) if ($a[0] > $iA) { $a[0] += strlen($insert1)-$lenBefore1; }
    }
}

function textToHTML(int $userId, string $text, bool $useBufferManager = true) {
    if (!$useBufferManager) return '';

    $chars = preg_split("//u", $text, -1, PREG_SPLIT_NO_EMPTY);
    $result = '<p>';

    $sEmoji = '';

    $sMarkerMode = false;

    $dMarkerMode = false;
    $activeMarker = null;
    $dMarkerArg = null;

    $sSpec = '';
    $ignoreSpec = false;
    $skipIfNewLine = false;

    $sMarkers = new Set([new SymbolMarker('**','b',$result),new SymbolMarker('//','i',$result),new SymbolMarker('--','s',$result)]);
    $dMarkers = new Set([
        new DoubleMarker('link',function($arg) {
            global $urlRegex;
            if ($arg == null) return ["[link]",'[/link]'];
            $arg = str_replace('"','\"',$arg);
            if ($arg == '' || preg_match($urlRegex, $arg) == 0) return ["[link=$arg]",'[/link]'];
            return ["<a href=\"$arg\">",'</a>'];
        }, $result),
        new DoubleMarker('cite',function($arg) use(&$skipIfNewLine) {
            $arg = str_replace('"','\"',$arg);
            $skipIfNewLine = true;
            return $arg == '' ? ['<blockquote>','</blockquote>'] : ["<blockquote data-cited=\"$arg\">",'</blockquote>'];
        }, $result),
        new DoubleMarker('spoil',function($arg) {
            $style = preg_match('/^block;?$/',$arg) > 0 ? ' style="display:block;"' : '';
            return ["<span class=\"spoil\"$style><span class=\"spoilTxt\">",'</span></span>'];
        }, $result)
    ]);
    $dMarkersToRemove = [];
    
    foreach ($chars as $char) {
        if ($dMarkerArg !== null) {
            switch (true) {
                case (preg_match('/^\]$/',$char) > 0):
                    if ($activeMarker == null) throw new \Exception('Parse error.'); // only needed cuz intelephense mark it otherwise
                    $activeMarker->markA(strlen($result),$dMarkerArg);
                    // print_r(PHP_EOL.'END, Argument: \''.$dMarkerArg."'".PHP_EOL);
                    $result .= "[$sSpec=$dMarkerArg]";
                    $sSpec = '';
                    $activeMarker = null;
                    $dMarkerArg = null;
                    $dMarkerMode = false;
                    break;
                default:
                    $dMarkerArg .= $char;
                    break;
            }
            continue;
        }

        if ($activeMarker != null) {
            if ($dMarkerMode) {
                if ($char == '=' && $sSpec[0] != '/') {
                    $dMarkerArg = '';
                    continue;
                } else if ($char == ']') {
                    // print_r(PHP_EOL.'END, sSpec[0]:'.$sSpec[0].PHP_EOL);
                    if ($sSpec[0] == '/') $activeMarker->markB(strlen($result));
                    else {
                        $dMarkerMode = false;
                        $activeMarker->markA(strlen($result));
                        $result .= "[$sSpec]";
                    }
                    $sSpec = '';
                    $activeMarker = null;
                    $dMarkerMode = false;
                    continue;
                }
                $activeMarker = null;
                $dMarkerMode = false;
                $result .= "[$sSpec";
                $sSpec = '';
            }
        }

        if ($sMarkerMode) {
            $sSpecLength = strlen($sSpec);
            // print_r(PHP_EOL.$char.'   '.$sSpec);

            foreach ($sMarkers as $m) {
                // print_r("A");
                $from = $m->from;
                if (isset($from[$sSpecLength]) && substr($from,0,$sSpecLength+1) == $sSpec.$char) {
                    // print_r("B");
                    $sSpec .= $char;
                    $finalLength = strlen($from);
                    if (strlen($sSpec) == $finalLength) {
                        $m->mark(strlen($result));
                        $sSpec = '';
                        $sMarkerMode = false;
                    }
                    continue 2;
                }
            }
            if ($sSpecLength == strlen($sSpec)) {
                // print_r("C");
                $result .= htmlspecialchars($sSpec);
                $sSpec = '';
                $sMarkerMode = false;
            }
        }

        if ($dMarkerMode) {
            $sSpecLength = strlen($sSpec);
            // print_r(PHP_EOL.$char .'   '. $sSpec);
            if ($sSpecLength == 0 && $char == '/') {
                $sSpec .= $char;
                continue;
            }

            $iFrom = $sSpecLength;
            if (isset($sSpec[0]) && $sSpec[0] == '/') $iFrom -= 1;

            foreach ($dMarkersToRemove as $d) $dMarkers->remove($d);
            foreach ($dMarkers as $m) {
                $from = $m->from;
                if (isset($from[$iFrom]) && $from[$iFrom] == $char) {
                    $sSpec .= $char;
                    // if ($sSpec[0] == '/') print_r(PHP_EOL.$sSpec);
                    $finalLength = strlen($from) + ($sSpec[0] == '/' ? 1 : 0);
                    if (strlen($sSpec) == $finalLength) $activeMarker = $m;
                    continue 2;
                }
                // if ($sSpecLength == strlen($sSpec)) $dMarkersToRemove[] = $m;
            }
            if ($sSpecLength == strlen($sSpec)) {
                $result .= '['.htmlspecialchars($sSpec);
                $sSpec = '';
                $dMarkerMode = false;
            }
        }

        if ($sEmoji != null) {
            if (preg_match('/^[\w\?\!]$/',$char) == 0) {
                if ($char == ':') {
                    $sEmoji .= $char;
                    if ($useBufferManager) {
                        UsersBuffer::requestEmoji($sEmoji, $userId);
                        $rowEmoji = UsersBuffer::getEmoji($sEmoji, $userId);
                        if ($rowEmoji == null) $result .= $sEmoji;
                        else {
                            $link = get_root_link('res').'/emojis/'.$rowEmoji['data']['id'];
                            $result .= <<<HTML
                            <img src="$link" alt="$sEmoji"/>
                            HTML;
                        }
                        $sEmoji = null;
                        continue;
                    }
                }
                $result .= $sEmoji.$char;
                $sEmoji = null;
                continue;
            } 
            $sEmoji .= $char;
            continue;
        }

        switch (true) {
            case ($char == '\\'):
                if ($ignoreSpec) { $ignoreSpec = false; $result .= htmlspecialchars($char); break; }
                $ignoreSpec = true;
                break;
            case (preg_match('/^[\*\/\-]$/',$char) > 0):
                if ($ignoreSpec) { $ignoreSpec = false; $result .= htmlspecialchars($char); break; }
                $sSpec = $char;
                $sMarkerMode = true;
                break;
            case ($char == ':'):
                if ($ignoreSpec) { $ignoreSpec = false; $result .= htmlspecialchars($char); break; }
                $sEmoji = ':';
                break;
            case (preg_match('/^\[$/',$char) > 0):
                if ($ignoreSpec) { $ignoreSpec = false; $result .= htmlspecialchars($char); break; }
                $dMarkerMode = true;
                break;
            case ($char == "\n"):
                if ($skipIfNewLine) { $skipIfNewLine = false; break; }
                if ($ignoreSpec) { $ignoreSpec = false; $result .= '\\'; break; }
                $result .= '<br/>';
                break;
            default:
                if ($ignoreSpec) { $ignoreSpec = false; $result .= '\\'; break; }
                $result .= htmlspecialchars($char);
                break;
        }
    }

    if ($sMarkerMode) $result .= $sSpec;
    else if ($dMarkerMode) $result .= $dMarkerArg !== null ? "[$sSpec=$dMarkerArg" : "[$sSpec";
    if ($sEmoji != null) $result .= $sEmoji;
    $result .= '</p>';

    return $result;
}

function textToHTML2(string $text) {
    class Buffer {
        public string $s = '';
        public string $raw = '';
        public static bool $skipIfNewLine = false;
        public static string $result = '';

        public static function init(bool &$skipIfNewLine, string &$result) {
            self::$skipIfNewLine =& $skipIfNewLine;
            self::$result =& $result;
        }
    
        public function add(string $s) {
            $this->s .= $s;
        }
        
        public function addToRaw(string $s) {
            global $urlRegex;

            $this->raw .= $s;
    
            if (preg_match('/(.*)?\[link=(.*)\]([\w ]*)\[\/link\]$/i', $this->raw, $m) > 0 && preg_match($urlRegex, $m[2]) > 0) {
                $before = htmlspecialchars($m[1]);
                $url = htmlspecialchars($m[2]);
                $urlText = htmlspecialchars($m[3]);
                $this->s = "$before<a href=$url>$urlText</a>";
                $this->flush();
                return true;
            } else if (preg_match('/(.*)?\[cite\]([\w ]*)\[\/cite\]$/i', $this->raw, $m) > 0) {
                $before = htmlspecialchars($m[1]);
                $text = htmlspecialchars($m[2]);
                $this->s = "$before<blockquote>$text</blockquote>";
                $this->flush();
                self::$skipIfNewLine = true;
                return true;
            } else if (preg_match('/(.*)?\[spoil\]([\w ]*)\[\/spoil\]$/i', $this->raw, $m) > 0) {
                $before = htmlspecialchars($m[1]);
                $text = htmlspecialchars($m[2]);
                $this->s = "$before<span class=\"spoil\"><span class=\"spoilTxt\">$text</span></span>";
                $this->flush();
                self::$skipIfNewLine = true;
                return true;
            }
            
            return false;
        }
    
        public function flush() {
            global $urlRegex;
            if (preg_match($urlRegex, $this->raw, $m) > 0) {
                $url = htmlspecialchars($this->raw);
                $this->s = "<a href=$url>$url</a>";
            }
    
            if ($this->s != "") self::$result .= $this->s;
            $this->s = "";
            $this->raw = "";
        }
        
        public function empty() {
            $this->s = "";
        }
    }

    $bParagraph = false;
    $bBold = false;
    $bItalic = false;
    $bStrike = false;
    $skipIfNewLine = false;
    $buffer = new Buffer();
    $specCharBuffer = new Buffer();
    $specCharRegex = '/[\\\\\/\*\-]/u';
    
    $chars = preg_split("//u", $text, -1, PREG_SPLIT_NO_EMPTY);
    $result = '';
    Buffer::init($skipIfNewLine, $result);

    foreach ($chars as $char) {
        if ($char == "\r") continue;
        else if ($buffer->addToRaw(htmlspecialchars($char))) continue;
        if ($skipIfNewLine) { $skipIfNewLine = false; if ($char == "\n") continue; }

        if (!$bParagraph) { $bParagraph = true; $result .= '<p>'; }
    
        if ($specCharBuffer->s != '') switch($char) {
            case ($specCharBuffer->s == "\\" && preg_match($specCharRegex,$char) > 0):
                $specCharBuffer->empty();
                $buffer->add($char);
                continue 2;
            case ($specCharBuffer->s == '*' && $char == '*'):
                $specCharBuffer->empty();
                $bBold = !$bBold;
                $buffer->add($bBold ? '<b>' : '</b>');
                continue 2;
            case ($specCharBuffer->s == '/' && $char == '/'):
                $specCharBuffer->empty();
                $bItalic = !$bItalic;
                $buffer->add($bItalic ? '<i>' : '</i>');
                continue 2;
            case ($specCharBuffer->s == '-' && $char == '-'):
                $specCharBuffer->empty();
                $bStrike = !$bStrike;
                $buffer->add($bStrike ? '<s>' : '</s>');
                continue 2;
            default:
                $buffer->add($specCharBuffer->s);
                $specCharBuffer->empty();
        }
    
        switch($char) {
            case (preg_match($specCharRegex,$char) > 0):
                $specCharBuffer->add($char);
                break;
            case ($char == "\n"):
                $buffer->flush();
                $result .= '<br/>';
                break;
            case ($char == ' '):
                $buffer->flush();
                $result .= '&nbsp';
                break;
            default:
                $buffer->add(htmlspecialchars($char));
                break;
        }
    }

    $buffer->flush();
    $specCharBuffer->flush();
    if ($bParagraph) $result .= '</p>';
    return $result;
}
?>