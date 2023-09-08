<?php
// Copyright (c) 2023 Bennet Becker <dev@bennet.cc>
//
// Permission is hereby granted, free of charge, to any person obtaining a copy
// of this software and associated documentation files (the "Software"), to deal
// in the Software without restriction, including without limitation the rights
// to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
// copies of the Software, and to permit persons to whom the Software is
// furnished to do so, subject to the following conditions:
//
// The above copyright notice and this permission notice shall be included in all
// copies or substantial portions of the Software.
//
// THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
// IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
// FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
// AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
// LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
// OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
// SOFTWARE.

include dirname(__FILE__)."/vendor/autoload.php";

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7;

const NC_LOG_NAME = "nextcloud_attachments";
const NC_LOG_FILE = "ncattach";

class Modifiable_Mail_mime extends \Mail_mime {
    public function __construct(\Mail_mime $other) {
        parent::__construct($other->build_params);

        $this->txtbody = $other->txtbody;
        $this->htmlbody = $other->htmlbody;
        $this->calbody = $other->calbody;
        $this->html_images = $other->html_images;
        $this->parts = $other->parts;
        $this->headers = $other->headers;
    }

    public function getParts(): array
    {
        return $this->parts;
    }

    public function setParts(array $parts): void
    {
        $this->parts = $parts;
    }

    public function setPart(int $i, array $part): void
    {
        $this->parts[$i] = $part;
    }
}

class nextcloud_attachments extends rcube_plugin
{
    private static function log($line): void
    {
        $rcmail = rcmail::get_instance();
        $lines = explode(PHP_EOL, $line);
        rcmail::write_log(NC_LOG_FILE, "[".NC_LOG_NAME."] ".$lines[0]);
        unset($lines[0]);
        if (count($lines) > 0) {
            foreach ($lines as $l) {
                rcmail::write_log(NC_LOG_FILE, str_pad("...",strlen("[".NC_LOG_NAME."] "), " ", STR_PAD_BOTH).$l);
            }
        }
    }
    public function init(): void
    {
        $this->add_hook("ready", function ($param) {
            $rcmail = rcmail::get_instance();
            // files are marked to cloud upload
            if (isset($_REQUEST['_target'] ) && $_REQUEST['_target'] == "cloud") {
                if (isset($_FILES["_attachments"]) && count($_FILES["_attachments"]) > 0) {
                    //set file sizes to 0 so rcmail_action_mail_attachment_upload::run() will not reject the files,
                    //so we can get it from rcube_uploads::insert_uploaded_file() later
                    $_FILES["_attachments"]["size"] = array_map(function ($e) {
                        return 0;
                    }, $_FILES["_attachments"]["size"]);
                } else {
                    self::log($rcmail->get_user_name()." - empty attachment array: ". print_r($_FILES, true));
                }
            }
        });

        //insert our client script and style
        $this->add_hook('html_editor', function ($params) {
            $this->include_script("client.js");
            $this->include_stylesheet("client.css");
            return $params;
        });

        //hook to upload the file
        $this->add_hook('attachment_upload', [$this, 'upload']);

        //action to check if we have a usable login
        $this->register_action('plugin.nextcloud_checklogin', [$this, 'check_login']);

        //action to trigger login flow
        $this->register_action('plugin.nextcloud_login', [$this, 'login']);

        //correct the cloud attachment size for retrieval
        $this->add_hook('attachment_get', function ($param) {
            if ($param["target"] === "cloud") {
                $param["mimetype"] = "application/nextcloud_attachment";
                $param["status"] = true;
                $param["size"] = strlen($param["data"]);
                unset($param["path"]);
            }
            return $param;
        });

        //login flow poll
        $this->add_hook("refresh", [$this, 'poll']);

        $this->add_hook("message_ready", function($args) {
            $msg = new Modifiable_Mail_mime($args["message"]);

            self::log(print_r($msg->getParts(), true));

            foreach ($msg->getParts() as $key => $part) {
                if($part['c_type'] === "application/nextcloud_attachment") {
                    $part["disposition"] = "inline";
                    $part["encoding"] = "quoted-printable";
                    $part["add_headers"] = [
                        "X-Mozilla-Cloud-Part" => "cloudFile"
                    ];
                    $msg->setPart($key, $part);
                }
            }
            return ["message" => $msg];
        });


    }

    public function poll($ignore): void
    {
        //check if there is poll endpoint
        if (isset($_SESSION['plugins']['nextcloud_attachments']['endpoint']) && isset($_SESSION['plugins']['nextcloud_attachments']['token'])) {
            $client = new GuzzleHttp\Client([
                'headers' => [
                    'User-Agent' => 'Roundcube Nextcloud Attachment Connector/1.0',
                ],
                'http_errors' => false
            ]);

            //poll it
            try {
                $res = $client->post($_SESSION['plugins']['nextcloud_attachments']['endpoint'] . "?token=" . $_SESSION['plugins']['nextcloud_attachments']['token']);

                //user finished login
                if($res->getStatusCode() == 200) {
                    $body = $res->getBody()->getContents();
                    $data = json_decode($body, true);
                    if (isset($data['appPassword']) && isset($data['loginName'])) {
                        $rcmail = rcmail::get_instance();
                        //save app password to user preferences
                        $prefs = $rcmail->user->get_prefs();
                        $prefs["nextcloud_login"] = $data;
                        $rcmail->user->save_prefs($prefs);
                        unset($_SESSION['plugins']['nextcloud_attachments']);
                        $rcmail->output->command('plugin.nextcloud_login_result', ['status' => "ok"]);
                    }
                } else if ($res->getStatusCode() != 404) { //login timed out
                    unset($_SESSION['plugins']['nextcloud_attachments']);
                }
            } catch (GuzzleException $e) {
                self::log("poll failed: ". print_r($e, true));
            }
        }
    }

    public function login() : void
    {
        $rcmail = rcmail::get_instance();

        $this->load_config();

        $server = $rcmail->config->get("nextcloud_attachment_server");

        if(empty($server)) {
            return;
        }

        $client = new GuzzleHttp\Client([
            'headers' => [
                'User-Agent' => 'Roundcube Nextcloud Attachment Connector/1.0',
            ],
            'http_errors' => false
        ]);

        //start login flow
        try {
            $res = $client->post($server . "/index.php/login/v2");

            $body = $res->getBody()->getContents();
            $data = json_decode($body, true);

            if($res->getStatusCode() !== 200) {
                self::log($rcmail->get_user_name()." login check request failed: ". print_r($data, true));
                $rcmail->output->command('plugin.nextcloud_login', [
                    'status' => null, "message" => $res->getReasonPhrase(), "response" => $data]);
                return;
            }

            //save poll endpoint and token to session
            $_SESSION['plugins']['nextcloud_attachments'] = $data['poll'];

            $rcmail->output->command('plugin.nextcloud_login', ['status' => "ok", "url" => $data["login"]]);
        } catch (GuzzleException $e) {
            self::log($rcmail->get_user_name()." login request failed: ". print_r($e, true));
            $rcmail->output->command('plugin.nextcloud_login', ['status' => null]);
        }
    }

    private function resolve_username($val): bool|string
    {
        $rcmail = rcmail::get_instance();

        $this->load_config();
        $method = $rcmail->config->get("nextcloud_attachment_username");

        switch ($method) {
            case "%s":
            case "plain":
            case "asis":
            case "copy":
                return $val;
            case "%u":
            case "username":
            case "localpart":
            case "stripdomain":
                return explode("@", $val)[0];
            case "email":
                if(strpos($val, "@") !== false) {
                    return $val;
                }
            case "ldap":
                return false;
            default:
                return false;

        }
    }

    public function check_login(): void
    {
        $rcmail = rcmail::get_instance();

        $prefs = $rcmail->user->get_prefs();
        $this->load_config();

        $server = $rcmail->config->get("nextcloud_attachment_server");

        $username = $this->resolve_username($rcmail->get_user_name());

        //missing config
        if (empty($server) || $username === false) {
            $rcmail->output->command('plugin.nextcloud_login_result', ['status' => null]);
            return;
        }

        //get app password and username or use rc ones
        $username = isset($prefs["nextcloud_login"]) ? $prefs["nextcloud_login"]["loginName"] : $this->resolve_username($rcmail->get_user_name());
        $password = isset($prefs["nextcloud_login"]) ? $prefs["nextcloud_login"]["appPassword"] : $rcmail->get_user_password();

        $client = new GuzzleHttp\Client([
            'auth' => [$username, $password],
            'http_errors' => false
        ]);

        //test webdav login
        try {
            $res = $client->request("PROPFIND", $server . "/remote.php/dav/files/" . $username);

            switch ($res->getStatusCode()) {
                case 401:
                    unset($prefs["nextcloud_login"]);
                    $rcmail->user->save_prefs($prefs);
                    //we can't use the password
                    $rcmail->output->command('plugin.nextcloud_login_result', ['status' => 'login_required']);
                    break;
                case 404:
                    unset($prefs["nextcloud_login"]);
                    $rcmail->user->save_prefs($prefs);
                    //the username does not exist
                    $rcmail->output->command('plugin.nextcloud_login_result', ['status' => 'invalid_user']);
                    break;
                case 200:
                case 207:
                    //we can log in
                    $rcmail->output->command('plugin.nextcloud_login_result', ['status' => 'ok']);
                    break;
                default:
                    unset($prefs["nextcloud_login"]);
                    $rcmail->user->save_prefs($prefs);
                    //something weired happened
                    $rcmail->output->command('plugin.nextcloud_login_result', ['status' => null, 'code' => $res->getStatusCode(), 'message' => $res->getReasonPhrase()]);
            }
        } catch (GuzzleException $e) {
            self::log($rcmail->get_user_name()." login check request failed: ". print_r($e, true));
            $rcmail->output->command('plugin.nextcloud_login_result', ['status' => null]);
        }
    }

    private function unique_filename($folder_uri, $filename, $username, $password): bool|string
    {
        $client = new GuzzleHttp\Client([
            'auth' => [$username, $password],
            'http_errors' => false
        ]);

        $fn = $filename;
        $i = 0;

        try {
            //iterate the folder until the filename is unique.
            while (($code = $client->request("PROPFIND", $folder_uri . "/" . rawurlencode($fn))->getStatusCode()) != 404) {
                $d = strrpos($filename, ".");
                $fn = substr($filename, 0, $d) . " " . ++$i . substr($filename, $d);
                if ($i > 100 || $code >= 500) {
                    return false;
                }
            }
        } catch (GuzzleException $e) {
            self::log($username." file request failed: ". print_r($e, true));
            return false;
        }

        return $fn;
    }

    public function upload($data) : array
    {
        if (!isset($_REQUEST['_target'] ) || $_REQUEST['_target'] !== "cloud") {
            //file not marked to cloud. we won't touch it.
            return $data;
        }

        $rcmail = rcmail::get_instance();

        $prefs = $rcmail->user->get_prefs();
        $this->load_config();

        //get app password and username or use rc ones
        $username = isset($prefs["nextcloud_login"]) ? $prefs["nextcloud_login"]["loginName"] : $this->resolve_username($rcmail->get_user_name());
        $password = isset($prefs["nextcloud_login"]) ? $prefs["nextcloud_login"]["appPassword"] : $rcmail->get_user_password();

        $server = $rcmail->config->get("nextcloud_attachment_server");
        $checksum = $rcmail->config->get("nextcloud_attachment_checksum", "sha256");

        $client = new GuzzleHttp\Client([
            'auth' => [$username, $password],
            'http_errors' => false
        ]);

        //server not configured
        if (empty($server) || $username === false) {
            $rcmail->output->command('plugin.nextcloud_upload_result', ['status' => 'no_config']);
            return ["status" => false, "abort" => true];
        }

        //get the attachment sub folder
        $folder = $rcmail->config->get("nextcloud_attachment_folder", "Mail Attachments");

        //full link with urlencoded folder (space must be %20 and not +)
        $folder_uri = $server."/remote.php/dav/files/".$username."/".rawurlencode($folder);

        //check folder
        $res = $client->request("PROPFIND", $folder_uri);

        if ($res->getStatusCode() == 404) { //folder does not exist
            //attempt to create the folder
            try {
                $res = $client->request("MKCOL", $folder_uri);

                if ($res->getStatusCode() != 201) { //creation failed
                    $body = $res->getBody()->getContents();
                    try {
                        $xml = new SimpleXMLElement($body);
                    } catch (Exception $e) {
                        self::log($username." xml parsing failed: ". print_r($e, true));
                        $xml = [];
                    }

                    $rcmail->output->command('plugin.nextcloud_upload_result', [
                        'status' => 'mkdir_error',
                        'code' => $res->getStatusCode(),
                        'message' => $res->getReasonPhrase(),
                        'result' => json_encode($xml)
                    ]);

                    self::log($username." mkcol failed ". $res->getStatusCode(). PHP_EOL . $res->getBody()->getContents());
                    return ["status" => false, "abort" => true, "error" => $res->getReasonPhrase()];
                }
            } catch (GuzzleException $e) {
                self::log($username." mkcol request failed: ". print_r($e, true));
            }
        } else if ($res->getStatusCode() > 400) { //we can't access the folder
            self::log($username." propfind failed ". $res->getStatusCode(). PHP_EOL . $res->getBody()->getContents());
            $rcmail->output->command('plugin.nextcloud_upload_result', ['status' => 'folder_error']);
            return ["status" => false, "abort" => true, "error" => $res->getReasonPhrase()];
        }

        //get unique filename
        $filename = $this->unique_filename($folder_uri, $data["name"], $username, $password);

        if ($filename === false) {
            self::log($username." filename determination failed");
            //it was not possible to find name
            //too many files?
            $rcmail->output->command('plugin.nextcloud_upload_result', ['status' => 'name_error']);
            return ["status" => false, "abort" => true];
        }

        //upload file
        $body = Psr7\Utils::tryFopen($data["path"], 'r');
        try {
            $res = $client->put($folder_uri . "/" . rawurlencode($filename), ["body" => $body]);

            if ($res->getStatusCode() != 200 && $res->getStatusCode() != 201) {
                $body = $res->getBody()->getContents();
                try {
                    $xml = new SimpleXMLElement($body);
                } catch (Exception $e) {
                    self::log($username." xml parsing failed: ". print_r($e, true));
                    $xml = [];
                }

                $rcmail->output->command('plugin.nextcloud_upload_result', [
                    'status' => 'upload_error', 'message' => $res->getReasonPhrase(), 'result' => json_encode($xml)]);
                return ["status" => false, "abort" => true, "error" => $res->getReasonPhrase()];
            }
        } catch (GuzzleException $e) {
            self::log($username." put failed: ". print_r($e, true));
            $rcmail->output->command('plugin.nextcloud_upload_result', ['status' => 'upload_error']);
            return ["status" => false, "abort" => true, "error" => $res->getReasonPhrase()];
        }

        //create share link
        try {
            $res = $client->post($server . "/ocs/v2.php/apps/files_sharing/api/v1/shares", [
                "headers" => [
                    "OCS-APIRequest" => "true"
                ],
                "form_params" => [
                    "path" => $folder . "/" . $filename,
                    "shareType" => 3,
                    "publicUpload" => "false",
                ]
            ]);

            $body = $res->getBody()->getContents();
            $url = "";
            $id = rand();
            if($res->getStatusCode() == 200) { //upload successful
                $ocs = new SimpleXMLElement($body);
                //inform client for insert to body
                $rcmail->output->command("plugin.nextcloud_upload_result", [
                    'status' => 'ok',
                    'result' => [
                        'url' => (string) $ocs->data->url,
                        'file' => [
                            'name' => $data["name"],
                            'size' => filesize($data["path"]),
                            'mimetype' => $data["mimetype"],
                            'id' => $id,
                            'group' => $data["group"],
                        ]
                    ]
                ]);
                $url = (string) $ocs->data->url;
            } else { //link creation failed. Permission issue?
                $body = $res->getBody()->getContents();
                try {
                    $xml = new SimpleXMLElement($body);
                } catch (Exception $e) {
                    self::log($username." xml parse failed: ". print_r($e, true));
                    $xml = [];
                }
                $rcmail->output->command('plugin.nextcloud_upload_result', [
                    'status' => 'link_error',
                    'code' => $res->getStatusCode(),
                    'message' => $res->getReasonPhrase(),
                    'result' => json_encode($xml)
                ]);
                return ["status" => false, "abort" => true, "error" => $res->getReasonPhrase()];
            }
        } catch (GuzzleException $e) {
            self::log($username." share file failed: ". print_r($e, true));
            $rcmail->output->command('plugin.nextcloud_upload_result', ['status' => 'link_error']);
            return ["status" => false, "abort" => true];
        }

        //fill out template attachment HTML
        $tmpl = file_get_contents(dirname(__FILE__)."/attachment_tmpl.html");

        $fs = filesize($data["path"]);
        $u = ["", "k", "M", "G", "T"];
        for($i = 0; $fs > 800.0 && $i <= count($u); $i++){ $fs /= 1024; }

        $mime_name = str_replace("/", "-", $data["mimetype"]);
        $mime_generic_name = str_replace("/", "-", explode("/", $data["mimetype"])[0])."-x-generic";

        $icon_path = dirname(__FILE__)."/icons/Yaru-mimetypes/";
        $mime_icon = file_exists($icon_path.$mime_name.".png") ?
            file_get_contents($icon_path.$mime_name.".png") : (
                file_exists($icon_path.$mime_generic_name.".png") ?
                    file_get_contents($icon_path.$mime_generic_name.".png") :
                    file_get_contents($icon_path."unknown.png"));


        $tmpl = str_replace("%FILENAME%", $data["name"], $tmpl);
        $tmpl = str_replace("%FILEURL%", $url, $tmpl);
        $tmpl = str_replace("%SERVERURL%", $server, $tmpl);
        $tmpl = str_replace("%FILESIZE%", round($fs,1)." ".$u[$i]."B", $tmpl);
        $tmpl = str_replace("%ICONBLOB%", base64_encode($mime_icon), $tmpl);
        $tmpl = str_replace("%CHECKSUM%", strtoupper($checksum)." ".hash_file($checksum, $data["path"]), $tmpl);

        unlink($data["path"]);

        //return a html page as attachment that provides the download link
        return [
            "id" => $id,
            "group" => $data["group"],
            "status" => true,
            "name" => $data["name"].".html", //append html suffix
            "mimetype" => "text/html",
            "data" => $tmpl, //just return the few KB text, we deleted the file
            "size" => strlen($tmpl),
            "target" => "cloud", //cloud attachment meta data
            "break" => true //no other plugin should process this attachment future
        ];
    }
}
