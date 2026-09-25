<?php
/**
 * RouterOS API client implementation.
 * Standard implementation for MikroTik API communication.
 */
class RouterosAPI
{
    public $debug     = false;
    public $connected = false;
    public $port      = 8728;
    public $timeout   = 3;
    public $attempts  = 5;
    public $delay     = 2;

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
        for ($a = 0; $a < $this->attempts; $a++) {
            $this->socket = @fsockopen($ip, $port, $this->error_no, $this->error_str, $this->timeout);
            if ($this->socket) {
                socket_set_timeout($this->socket, $this->timeout);
                
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
        $byte = ord(fread($this->socket, 1));
        $length = 0;
        if (($byte & 0x80) == 0x00) {
            $length = $byte;
        } elseif (($byte & 0xC0) == 0x80) {
            $length = (($byte & 0x3F) << 8) + ord(fread($this->socket, 1));
        } elseif (($byte & 0xE0) == 0xC0) {
            $length = (($byte & 0x1F) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
        } elseif (($byte & 0xF0) == 0xE0) {
            $length = (($byte & 0x0F) << 24) + (ord(fread($this->socket, 1)) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
        } elseif (($byte & 0xF8) == 0xF0) {
            $length = (ord(fread($this->socket, 1)) << 24) + (ord(fread($this->socket, 1)) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
        }
        
        $word = '';
        while ($length > 0) {
            $chunk = fread($this->socket, $length);
            $word .= $chunk;
            $length -= strlen($chunk);
        }
        return $word;
    }

    public function read($parse = true)
    {
        $response = array();
        while (true) {
            $word = $this->readWord();
            if ($word == '') {
                continue;
            }
            $response[] = $word;
            if ($word == '!done' || $word == '!fatal') {
                break;
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
            $this->write('=' . $k . '=' . $v, ($i == $c - 1));
            $i++;
        }
        return $this->read();
    }

    public function parseResponse($response)
    {
        $result = array();
        $current = null;
        foreach ($response as $x) {
            if ($x === '!re') {
                if ($current !== null) {
                    $result[] = $current;
                }
                $current = array();
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
