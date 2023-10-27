<?php
namespace LDLib\Parser;

use Ds\Set;
use Schema\UsersBuffer;
use LDLib\AWS\AWS;
use LDLib\User\RegisteredUser;

use function LDLib\Database\get_tracked_pdo;

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

class SoloKeywordMarker {
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

    public function insert(?string $arg=null) {
        $this->result .= call_user_func($this->{'to'},$arg);
    }
}

function textToHTML(int $userId, string $text, bool $commitData = false, bool $useBufferManager = true) {
    if (!$useBufferManager) return '';
    $resPath = get_root_link('res');

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

    $conn = null;

    $sMarkers = new Set([new SymbolMarker('**','b',$result),new SymbolMarker('//','i',$result),new SymbolMarker('--','s',$result)]);
    $kwMarkers = new Set([
        new KeywordMarker('link',function($arg) {
            global $urlRegex;
            if ($arg == null) return ["[link]",'[/link]'];
            $arg = str_replace('"','\"',$arg);
            if ($arg == '' || preg_match($urlRegex, $arg) == 0) return ["[link=$arg]",'[/link]'];
            return ["<a href=\"$arg\" target=\"_blank\">",'</a>'];
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
        }, $result),
        new SoloKeywordMarker('file',function ($arg) use(&$commitData,&$userId,&$conn) {
            if (!$commitData) return "<span class='processThis'>insertFileLocal:$arg</span>";
            $arg = str_replace(['.',' '],'_',$arg);

            if (!isset($_FILES[$arg]) || $_FILES[$arg]['size'] > 25000000) return '<span class="error">Failed upload.</span>';
            $file = $_FILES[$arg];

            $conn ??= get_tracked_pdo();

            UsersBuffer::requestFromId($userId);
            $row = UsersBuffer::getFromId($userId);
            if ($row['data'] == null) return '<span class="error">Failed upload.</span>';
            $user = RegisteredUser::initFromRow($row);

            $s3client = AWS::getS3Client();
            $res = $s3client->putObject($conn,$user,$file,false,true);
            if (!($res->resultType instanceof \LDLib\General\SuccessType)) return '<span class="error">Failed upload.</span>';

            return "<span class='processThis'>insertFile:{$file['type']};{$res->data[0]}</span>";
        }, $result),
        new SoloKeywordMarker('card',function($arg) use(&$commitData, &$resPath) {
            $arg = preg_replace('/\s/','',$arg);
            return "<span class=\"gadget card\"><img src=\"$resPath/design/balises/card.png\" data-generator=\"$arg\"/><span class=\"value\">".($commitData ? get_random_card_value($arg) : '??')."</span></span>";
        }, $result),
        new SoloKeywordMarker('letter',function($arg) use(&$commitData, &$resPath) {
            $arg = preg_replace('/\s/','',$arg);
            $v = get_random_letter_value($arg,$type);
            switch ($type) {
                case 'consonant': $src = "$resPath/design/balises/conson.png"; break;
                case 'vowel': $src = "$resPath/design/balises/vowel.png"; break;
                default: $src = "$resPath/design/balises/letter.png"; break;
            }
            return "<span class=\"gadget\"><img src=\"$src\" data-generator=\"$arg\"/><span class=\"value\">".($commitData ? $v : '??')."</span></span>";
        }, $result)
    ]);
    $kwMarkersToSkip = new Set();
    
    foreach ($chars as $char) {
        if ($kwMarkerArg !== null) {
            switch (true) {
                case $ignoreSpec:
                    $ignoreSpec = false;
                    $kwMarkerArg .= htmlspecialchars($char);
                    break;
                case $char == '\\':
                    $ignoreSpec = true;
                    break;
                case (preg_match('/^\]$/',$char) > 0):
                    if ($activeMarker == null) throw new \Exception('Parse error.'); // only needed cuz intelephense mark it otherwise

                    if (substr($kwMarkerArg,-1) == '/' && $activeMarker instanceof SoloKeywordMarker) {
                        $kwMarkerArg = substr($kwMarkerArg,0,strlen($kwMarkerArg)-1);
                        $activeMarker->insert($kwMarkerArg);
                    } else if ($activeMarker instanceof KeywordMarker) {
                        $activeMarker->markA(strlen($result),$kwMarkerArg);
                        // print_r(PHP_EOL.'END, Argument: \''.$kwMarkerArg."'".PHP_EOL);
                        $result .= "[$sSpec=$kwMarkerArg]";
                    } else {
                        $result .= "[$sSpec=$kwMarkerArg]";
                    }

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
                    if ($activeMarker instanceof KeywordMarker) {
                        if ($sSpec[0] == '/') $activeMarker->markB(strlen($result));
                        else {
                            $kwMarkerMode = false;
                            $activeMarker->markA(strlen($result));
                            $result .= "[$sSpec]";
                        }
                    } else if ($activeMarker instanceof SoloKeywordMarker && substr($sSpec,-1) == '/') {
                        $sSpec = substr($sSpec,0,strlen($sSpec)-1);
                        $activeMarker->insert();
                    }
                    $sSpec = '';
                    $activeMarker = null;
                    $kwMarkerMode = false;
                    $kwMarkersToSkip->clear();
                    continue;
                } else if ($char == '/') {
                    $sSpec .= $char;
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
            case $ignoreSpec:
                $ignoreSpec = false;
                $result .= htmlspecialchars($char);
                break;
            case ($char == '\\'):
                $ignoreSpec = true;
                break;
            case (preg_match('/^[\*\/\-]$/',$char) > 0):
                $sSpec = $char;
                $sMarkerMode = true;
                break;
            case ($char == ':'):
                $sEmoji = ':';
                break;
            case (preg_match('/^\[$/',$char) > 0):
                $kwMarkerMode = true;
                break;
            case ($char == "\n"):
                if ($skipIfNewLine) { $skipIfNewLine = false; break; }
                $result .= '<br/>';
                break;
            default:
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

function get_random_card_value($arg) {
    $vals = explode(';',$arg);
    $cards = [];

    $conv = static function ($n) {
        switch ($n) {
            case 1: return 'As';
            case 11: return 'Valet';
            case 12: return 'Dame';
            case 13: return 'Roi';
            default:
                if ($n < 0) return 'As';
                else if ($n > 13) return 'Roi';
                else return $n;
        }
    };

    foreach ($vals as $val) {
        if (preg_match('/(\d+)(?:-(\d+))?((?:,?(?:Trèfle|Pique|Carreau|Coeur|T|P|Ca|Co))+)?/i',$val,$m) > 0) {
            $min = isset($m[2]) ? min((int)$m[1],(int)$m[2]) : (int)$m[1];
            $max = isset($m[2]) ? max((int)$m[1],(int)$m[2]) : $min;
            if (!isset($m[3])) {
                for ($i=$min; $i<=$max; $i++) array_push($cards, $conv($i).' de Trèfle', $conv($i).' de Pique', $conv($i).' de Carreau', $conv($i).' de Coeur');
            } else {
                $aKind = explode(',',$m[3]);
                foreach ($aKind as $s) switch (strtolower($s)) {
                    case 'trèfle': case 't': for ($i=$min; $i<=$max; $i++) array_push($cards, $conv($i).' de Trèfle'); break;
                    case 'pique': case 'p': for ($i=$min; $i<=$max; $i++) array_push($cards, $conv($i).' de Pique'); break;
                    case 'carreau': case 'ca': for ($i=$min; $i<=$max; $i++) array_push($cards, $conv($i).' de Carreau'); break;
                    case 'coeur': case 'co': for ($i=$min; $i<=$max; $i++) array_push($cards, $conv($i).' de Coeur'); break;
                }
            }
        }
    }

    if (empty($cards)) return get_random_card_value('1-13');

    return $cards[random_int(0,count($cards)-1)];
}

function get_random_letter_value($arg, &$sType='') {
    $vals = explode(';',$arg);
    $letters = [];

    $scrabblePush = static function(&$array, $letter) {
        $n = 1;
        switch ($letter) {
            case 'E': $n = 15; break;
            case 'A': $n = 9; break;
            case 'I': $n = 8; break;
            case 'N': case 'O': case 'R': case 'S': case 'T': case 'U': $n = 6; break;
            case 'L': $n = 5; break;
            case 'D': case 'M': $n = 3; break;
            case 'B': case 'C': case 'F': case 'G': case 'H': case 'P': case 'V': $n = 2; break;
            case 'J': case 'K': case 'Q': case 'W': case 'X': case 'Y': case 'Z': $n = 1; break;
        }
        for ($i=0; $i<$n; $i++) array_push($array, $letter);
    };

    foreach ($vals as $val) {
        if (preg_match('/^\s*([a-z])(?:-([a-z])\s*(?:,\s*(scrabble))?)?\s*$/i',$val,$m) > 0) {
            if (!isset($m[2])) { array_push($letters,strtoupper($m[1])); continue; }
            $min = min(ord(strtoupper($m[1])),ord(strtoupper($m[2])));
            $max = max(ord(strtoupper($m[1])),ord(strtoupper($m[2])));
            $scrabble = isset($m[3]) && $m[3] === 'scrabble';
            for ($i=$min; $i<=$max || $i <= 90; $i++) {
                if ($scrabble) $scrabblePush($letters,chr($i));
                else array_push($letters, chr($i));
            }
        } else if (preg_match('/^\s*(consonne|voyelle)\s*$/i',$val,$m) > 0) {
            switch ($m[1]) {
                case 'consonne': array_push($letters,'B','C','D','F','G','H','J','K','L','M','N','P','Q','R','S','T','V','W','X','Z'); break;
                case 'voyelle': array_push($letters,'A','E','I','O','U','Y'); break;
            }
        }
    }

    if (empty($letters)) return get_random_letter_value('A-Z');

    $sLetters = implode('',$letters);
    if (strlen($sLetters) == 6 && preg_match('/^[AEIOUY]+$/',$sLetters) > 0) $sType = 'vowel';
    else if (strlen($sLetters) == 20 && preg_match('/^[BCDFGHJKLMNPQRSTVWXZ]+$/',$sLetters) > 0) $sType = 'consonant';

    return $letters[random_int(0,count($letters)-1)];
}
?>