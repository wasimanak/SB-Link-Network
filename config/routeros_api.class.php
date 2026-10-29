<?php
/**
 * RouterOS API client implementation.
 * Standard implementation for MikroTik API communication.
 */
if (!class_exists('RouterosAPI')) {
class RouterosAPI
{
    public $debug     = false;
    public $connected = false;
    public $port      = 8728;
    public $timeout   = 15;
    public $connect_timeout = 3;
    public $attempts  = 2;
    public $delay     = 0;
    public $last_is_done = false;

    private $socket;
    private $error_no;
    private $error_str;

    public function debug($text)
    {
        if ($this->debug) {
            echo $text . "\n";
        }
    }

    private function encodeLength($length)
    {
        if ($length < 0x80) {
            $length = chr($length);
        } elseif ($length < 0x4000) {
            $length |= 0x8000;
            $length = chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        } elseif ($length < 0x200000) {
            $length |= 0xC00000;
            $length = chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        } elseif ($length < 0x10000000) {
            $length |= 0xE0000000;
            $length = chr(($length >> 24) & 0xFF) . chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        } elseif ($length >= 0x10000000) {
            $length = chr(0xF0) . chr(($length >> 24) & 0xFF) . chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        }
        return $length;
    }

    public function connect($ip, $login, $password, $port = 8728)
    {
        $conn_timeout = !empty($this->connect_timeout) ? $this->connect_timeout : 3;
        for ($a = 0; $a < $this->attempts; $a++) {
            $this->socket = @fsockopen($ip, $port, $this->error_no, $this->error_str, $conn_timeout);
            if ($this->socket) {
                if (function_exists('stream_set_timeout')) {
                    @stream_set_timeout($this->socket, $this->timeout);
                }
                if (function_exists('stream_set_read_buffer')) {
                    @stream_set_read_buffer($this->socket, 8192);
                }
                
                // New RouterOS (v6.43+ & v7) login method
                $this->write('/login', false);
                $this->write('=name=' . $login, false);
                $this->write('=password=' . $password);
                $response = $this->read(false);

                if (isset($response[0]) && $response[0] == '!done') {
                    // If no challenge returned, we are successfully logged in (ROS v6.43+ & v7)
                    if (!isset($response[1])) {
                        $this->connected = true;
                        return true;
                    } 
                    // Old RouterOS (pre v6.43) login challenge method
                    else {
                        $matches = array();
                        if (preg_match('/^=ret=(.*)$/', $response[1], $matches)) {
                            $chal = pack('H*', $matches[1]);
                            $md5 = md5(chr(0) . $password . $chal);
                            $this->write('/login', false);
                            $this->write('=name=' . $login, false);
                            $this->write('=response=00' . $md5);
                            $res2 = $this->read(false);
                            if (isset($res2[0]) && $res2[0] == '!done') {
                                $this->connected = true;
                                return true;
                            }
                        }
                    }
                }
                fclose($this->socket);
            }
            sleep($this->delay);
        }
        return false;
    }

    public function disconnect()
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->connected = false;
    }

    private function readWord()
    {
        if (!$this->socket || feof($this->socket)) {
            return false;
        }
        $char = @fread($this->socket, 1);
        if ($char === false || $char === '') {
            return false;
        }
        $byte = ord($char);
        $length = 0;
        if (($byte & 0x80) == 0x00) {
            $length = $byte;
        } elseif (($byte & 0xC0) == 0x80) {
            $ch = @fread($this->socket, 1);
            if ($ch === false || $ch === '') return false;
            $length = (($byte & 0x3F) << 8) + ord($ch);
        } elseif (($byte & 0xE0) == 0xC0) {
            $ch1 = @fread($this->socket, 1);
            $ch2 = @fread($this->socket, 1);
            if ($ch1 === false || $ch2 === false || $ch1 === '' || $ch2 === '') return false;
            $length = (($byte & 0x1F) << 16) + (ord($ch1) << 8) + ord($ch2);
        } elseif (($byte & 0xF0) == 0xE0) {
            $ch1 = @fread($this->socket, 1);
            $ch2 = @fread($this->socket, 1);
            $ch3 = @fread($this->socket, 1);
            if ($ch1 === false || $ch2 === false || $ch3 === false || $ch1 === '' || $ch2 === '' || $ch3 === '') return false;
            $length = (($byte & 0x0F) << 24) + (ord($ch1) << 16) + (ord($ch2) << 8) + ord($ch3);
        } elseif (($byte & 0xF8) == 0xF0) {
            $ch1 = @fread($this->socket, 1);
            $ch2 = @fread($this->socket, 1);
            $ch3 = @fread($this->socket, 1);
            $ch4 = @fread($this->socket, 1);
            if ($ch1 === false || $ch2 === false || $ch3 === false || $ch4 === false || $ch1 === '' || $ch2 === '' || $ch3 === '' || $ch4 === '') return false;
            $length = (ord($ch1) << 24) + (ord($ch2) << 16) + (ord($ch3) << 8) + ord($ch4);
        }
        
        $word = '';
        while ($length > 0) {
            $chunk = @fread($this->socket, $length);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $word .= $chunk;
            $length -= strlen($chunk);
        }
        return $word;
    }

    public function read($parse = true)
    {
        $this->last_is_done = false;
        $response = array();
        $is_done = false;
        while (true) {
            if (!$this->socket || feof($this->socket)) {
                break;
            }
            $meta = stream_get_meta_data($this->socket);
            if (!empty($meta['timed_out'])) {
                break;
            }
            $word = $this->readWord();
            if ($word === false) {
                break;
            }
            if ($word === '') {
                // Empty word terminates a sentence in MikroTik API.
                // If we have already received !done or !fatal, this marks the end of the entire reply.
                if ($is_done) {
                    $this->last_is_done = true;
                    break;
                }
                // Otherwise, continue reading subsequent sentences in multi-sentence replies
                continue;
            }
            $response[] = $word;
            if ($word === '!done' || $word === '!fatal') {
                $is_done = true;
            }
        }
        if ($parse) {
            $response = $this->parseResponse($response);
        }
        return $response;
    }

    public function write($command, $param2 = true)
    {
        if ($command) {
            $data = explode("\n", $command);
            foreach ($data as $com) {
                $com = trim($com);
                fwrite($this->socket, $this->encodeLength(strlen($com)) . $com);
            }
            if ($param2) {
                fwrite($this->socket, chr(0));
            }
        }
    }

    public function comm($com, $arr = array())
    {
        $this->write($com, empty($arr));
        $i = 0;
        $c = count($arr);
        foreach ($arr as $k => $v) {
            if (strpos($k, '?') === 0) {
                $word = $k . ($v !== '' ? '=' . $v : '');
            } elseif (strpos($k, '=') === 0) {
                $word = $k . ($v !== '' ? '=' . $v : '');
            } else {
                $word = '=' . $k . ($v !== '' ? '=' . $v : '');
            }
            $this->write($word, ($i == $c - 1));
            $i++;
        }
        return $this->read();
    }

    public function parseResponse($response)
    {
        $result = array();
        $current = null;
        foreach ($response as $x) {
            if ($x === '!re' || $x === '!trap') {
                if ($current !== null) {
                    $result[] = $current;
                }
                $current = array();
                if ($x === '!trap') {
                    $current['!trap'] = true;
                }
            } elseif ($x === '!done' || $x === '!fatal') {
                if ($current !== null) {
                    $result[] = $current;
                }
                $current = null;
            } elseif (strpos($x, '=') === 0) {
                $parts = explode('=', substr($x, 1), 2);
                if (count($parts) == 2 && $current !== null) {
                    $current[$parts[0]] = $parts[1];
                }
            }
        }
        return $result;
    }
}
}
