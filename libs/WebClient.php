<?php

class WebClient
{
    private $ch;
    private $cookie = '';

    public function Navigate($url, $post = [])
    {
        curl_setopt($this->ch, CURLOPT_URL, $url);
        curl_setopt($this->ch, CURLOPT_COOKIE, $this->cookie);
        if (!empty($post)) {
            curl_setopt($this->ch, CURLOPT_POST, true);
            curl_setopt($this->ch, CURLOPT_POSTFIELDS, $post);
        }
        $response = $this->exec();
        if ($response['Code'] !== 200) {
            return false;
        }
        //echo curl_getinfo($this->ch, CURLINFO_HEADER_OUT);
        return $response['Html'];
    }

    public function __construct()
    {
        $this->init();
    }

    public function __destruct()
    {
        $this->close();
    }

    private function init(): void
    {
        $this->ch = curl_init();
        curl_setopt($this->ch, CURLOPT_USERAGENT, 'Mozilla/6.0 (Windows NT 6.2; WOW64; rv:16.0.1) Gecko/20121011 Firefox/16.0.1');
        curl_setopt($this->ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($this->ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($this->ch, CURLINFO_HEADER_OUT, true);
        curl_setopt($this->ch, CURLOPT_HEADER, true);
        curl_setopt($this->ch, CURLOPT_AUTOREFERER, true);
        curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->ch, CURLOPT_CONNECTTIMEOUT, 20);
        curl_setopt($this->ch, CURLOPT_TIMEOUT, 30); //timeout in seconds
    }

    private function exec(): array
    {
        $headers = [];
        $html = '';
        $separator = "\r\n\r\n";

        $output = curl_exec($this->ch);

        $httpcode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);

        if ($httpcode === 200) {

            $separatorpos = strpos($output, $separator);

            $html = substr($output, $separatorpos + strlen($separator));

            $h = trim(substr($output,0,$separatorpos));
            $lines = explode("\n", $h);
            foreach($lines as $line) {
                $kv = explode(':',$line);

                if (count($kv) === 2) {
                    $k = trim($kv[0]);
                    $v = trim($kv[1]);
                    $headers[$k] = $v;
                }
            }
        }

        // TODO: it would deserve to be tested extensively.
        if (!empty($headers['Set-Cookie'])) {
            $this->cookie = $headers['Set-Cookie'];
        }

        return ['Code' => $httpcode, 'Headers' => $headers, 'Html' => $html];
    }

    private function close(): void
    {
        curl_close($this->ch);
    }

}