<?php
namespace LDLib\Parser;

$urlRegex = '/(^(https?|ftp):\/\/(\S*?\.\S*?))([\s)\[\]{},;"\':<]|\.\s|$)/i';

function textToHTML(string $text) {
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
                $this->s = "$before<span class=\"comm_spoil\">$text</span>";
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
        else if ($char != ' ' && $buffer->addToRaw(htmlspecialchars($char))) continue;
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