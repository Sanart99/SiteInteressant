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
        $middle = substr($result,$iA+strlen($from),$i-($iA+strlen($from)));
        $insert2 = "</$to>";
        $end = substr($result,$i);
        $this->result = $before . $insert1 . $middle . $insert2 . $end;
        $this->iA = null;

        $diff1 = strlen($insert1)-strlen($from);
        foreach (SymbolMarker::$all as $o) if ($o != $this && $o->iA != null && $o->iA > $iA) { $o->iA += $diff1; }
        foreach (KeywordMarker::$all as $o) foreach ($o->arrA as &$a) if ($a[0] > $iA) { $a[0] += $diff1; }
    }
}

class KeywordMarker {
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
        $lenBefore1 = strlen($arg === null ? "[$from]" : "[{$from}={$arg}]");

        $middle = substr($result,$iA+$lenBefore1,$iB-($iA+$lenBefore1));
        $to = call_user_func($this->{'to'},$arg);

        $before = substr($result,0,$iA);
        $insert1 = $to[0];
        $insert2 = $to[1];
        $end = substr($result,$iB);
        $this->result = $before . $insert1 . $middle . $insert2 . $end;

        $diff1 = strlen($insert1)-$lenBefore1;
        foreach (SymbolMarker::$all as $o) if ($o != $this && $o->iA != null && $o->iA > $iA) { $o->iA += $diff1; }
        foreach (KeywordMarker::$all as $o) foreach ($o->arrA as &$a) if ($a[0] > $iA) { $a[0] += $diff1; }
    }
}

function textToHTML(int $userId, string $text, bool $useBufferManager = true) {
    if (!$useBufferManager) return '';

    $chars = preg_split("//u", $text, -1, PREG_SPLIT_NO_EMPTY);
    $result = '<p>';

    $sEmoji = '';

    $sMarkerMode = false;

    $kwMarkerMode = false;
    $activeMarker = null;
    $kwMarkerArg = null;

    $sSpec = '';
    $ignoreSpec = false;
    $skipIfNewLine = false;

    $sMarkers = new Set([new SymbolMarker('**','b',$result),new SymbolMarker('//','i',$result),new SymbolMarker('--','s',$result)]);
    $kwMarkers = new Set([
        new KeywordMarker('link',function($arg) {
            global $urlRegex;
            if ($arg == null) return ["[link]",'[/link]'];
            $arg = str_replace('"','\"',$arg);
            if ($arg == '' || preg_match($urlRegex, $arg) == 0) return ["[link=$arg]",'[/link]'];
            return ["<a href=\"$arg\">",'</a>'];
        }, $result),
        new KeywordMarker('cite',function($arg) use(&$skipIfNewLine) {
            $skipIfNewLine = true;
            return $arg == '' ? ['</p><blockquote><p>','</p></blockquote><p>'] : ["</p><p class=\"preQuote\">$arg</p><blockquote><p>",'</p></blockquote><p>'];
        }, $result),
        new KeywordMarker('spoil',function($arg) use(&$skipIfNewLine) {
            $block = preg_match('/(?:^|;)\s*block\s*(?:$|;)/',$arg) > 0;
            if ($block) $skipIfNewLine = true;
            return $block == true ? ['</p><p class="spoil"><span class="spoilTxt">','</span></p><p>'] : ['<span class="spoil"><span class="spoilTxt">','</span></span>'];
        }, $result),
        new KeywordMarker('code',function($arg) use(&$skipIfNewLine) {
            $skipIfNewLine = true;
            return ['</p><pre><code>','</code></pre><p>'];
        }, $result),
        new KeywordMarker('rp',function($arg) use(&$skipIfNewLine) {
            $skipIfNewLine = true;
            $sArg = preg_match('/^[^<>]+$/',$arg,$m) > 0 ? "<div class=\"rpTextSpeaker\"><p>{$m[0]}</p></div>" : '';
            return ["</p>$sArg<div class=\"rpText\"><p>",'</p></div><p>'];
        }, $result)
    ]);
    $kwMarkersToSkip = new Set();
    
    foreach ($chars as $char) {
        if ($kwMarkerArg !== null) {
            switch (true) {
                case (preg_match('/^\]$/',$char) > 0):
                    if ($activeMarker == null) throw new \Exception('Parse error.'); // only needed cuz intelephense mark it otherwise
                    $activeMarker->markA(strlen($result),$kwMarkerArg);
                    // print_r(PHP_EOL.'END, Argument: \''.$kwMarkerArg."'".PHP_EOL);
                    $result .= "[$sSpec=$kwMarkerArg]";
                    $sSpec = '';
                    $activeMarker = null;
                    $kwMarkerArg = null;
                    $kwMarkerMode = false;
                    $kwMarkersToSkip->clear();
                    break;
                default:
                    $kwMarkerArg .= htmlspecialchars($char);
                    break;
            }
            continue;
        }

        if ($activeMarker != null) {
            if ($kwMarkerMode) {
                if ($char == '=' && $sSpec[0] != '/') {
                    $kwMarkerArg = '';
                    continue;
                } else if ($char == ']') {
                    // print_r(PHP_EOL.'END, sSpec[0]:'.$sSpec[0].PHP_EOL);
                    if ($sSpec[0] == '/') $activeMarker->markB(strlen($result));
                    else {
                        $kwMarkerMode = false;
                        $activeMarker->markA(strlen($result));
                        $result .= "[$sSpec]";
                    }
                    $sSpec = '';
                    $activeMarker = null;
                    $kwMarkerMode = false;
                    $kwMarkersToSkip->clear();
                    continue;
                }
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

        if ($kwMarkerMode) {
            $sSpecLength = strlen($sSpec);
            // print_r(PHP_EOL.$char .'   '. $sSpec);
            if ($sSpecLength == 0 && $char == '/') {
                $sSpec .= $char;
                continue;
            }

            $iFrom = $sSpecLength;
            if (isset($sSpec[0]) && $sSpec[0] == '/') $iFrom -= 1;

            foreach ($kwMarkers as $m) if (!$kwMarkersToSkip->contains($m)) {
                $from = $m->from;
                if (isset($from[$iFrom]) && $from[$iFrom] == $char) {
                    $sSpec .= $char;
                    // if ($sSpec[0] == '/') print_r(PHP_EOL.$sSpec);
                    $finalLength = strlen($from) + ($sSpec[0] == '/' ? 1 : 0);
                    if (strlen($sSpec) == $finalLength) $activeMarker = $m;
                    continue 2;
                }
                if ($sSpecLength == strlen($sSpec)) $kwMarkersToSkip->add($m);
            }
            if ($sSpecLength == strlen($sSpec)) {
                $result .= '['.htmlspecialchars($sSpec);
                $sSpec = '';
                $activeMarker = null;
                $kwMarkerMode = false;
                $kwMarkersToSkip->clear();
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
                $kwMarkerMode = true;
                break;
            case ($char == "\n"):
                if ($skipIfNewLine) { $skipIfNewLine = false; break; }
                if ($ignoreSpec) { $ignoreSpec = false; $result .= '\\'; break; }
                $result .= '<br/>';
                break;
            default:
                if ($ignoreSpec) { $ignoreSpec = false; $result .= '\\'; break; }
                $skipIfNewLine = false;
                $result .= htmlspecialchars($char);
                break;
        }
    }

    if ($sMarkerMode) $result .= $sSpec;
    else if ($kwMarkerMode) $result .= $kwMarkerArg !== null ? "[$sSpec=$kwMarkerArg" : "[$sSpec";
    if ($sEmoji != null) $result .= $sEmoji;
    $result .= '</p>';

    $correctedResult = '';
    $tagActive = [];
    preg_match_all('/((?:<.+?>)*?(?:<p(?: .*?)?>|<pre><code>))((?:.|\s)*?)((?:<\/p>|<\/code><\/pre>)(?:<\/.+?>)*)/',$result,$mParts,PREG_SET_ORDER);
    foreach ($mParts as $part) {
        $correctedResult .= $part[1] . implode($tagActive);
        preg_match_all('/(?(DEFINE)(?P<chars>[^<>]|<\w+.*?\/>|))(?:(?<begin>(?P>chars)*)(?:(?<A1>(?:<(?!\/).*?>)+.*?)(?<B>(?:<\/.*?>)+)|(?<A2>(?:<\/.*?>)+)|(?<A3>(?:<(?!\/).*?>)+(?P>chars)*))?(?<end>(?P>chars)*))/',$part[2],$m,PREG_SET_ORDER|PREG_UNMATCHED_AS_NULL);
        for ($iM=0; $iM<count($m); $iM++) {
            $nextIsEmpty = isset($m[$iM+1]) && $m[$iM+1][0] == '';
            $v = $m[$iM];
            preg_match_all('/<\w+>/',$v[0],$mA);
            preg_match_all('/<\/\w+>/',$v[0],$mB);
            $mA = array_merge($tagActive,$mA[0]);
            $mB = $mB[0];
            $tagActive = [];

            if (isset($v['begin'])) $correctedResult .= $v['begin'];
            if (isset($v['A1'])) $correctedResult .= $v['A1'];
            else if (isset($v['A3'])) { $correctedResult .= $v[0]; }
            
            for ($i=count($mA)-1; $i>=0; $i--) {                
                $tagA = $mA[$i];
                $tagB = str_replace('<','</',$tagA);
                
                $i2Start = count($mA)-1-$i;
                $found = false;
                for ($i2=$i2Start; $i2<count($mB); $i2++) if ($mB[$i2] == $tagB) {
                    if ($i2 != $i2Start) {
                        $temp = $mB[$i2];
                        $mB[$i2] = $mB[$i2Start];
                        $mB[$i2Start] = $temp;
                    }
                    $found = true;
                    break;
                }
                
                if (!$found) {
                    array_splice($mB,$i2Start,0,$tagB);
                    $tagActive[] = $tagA;
                }
            }
            
            $correctedResult .= implode('',$mB);
            if (!isset($v['A3']) && !$nextIsEmpty) $correctedResult .= implode('',$tagActive);
            $correctedResult .= $v['end'];
            if ($nextIsEmpty) break;
        }
        $correctedResult .= $part[3];
    }
    return $correctedResult;
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