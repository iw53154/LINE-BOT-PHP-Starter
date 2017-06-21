<?php	
	
	class phpMQTT {
		private $socket; 			/* holds the socket	*/
		private $msgid = 1;			/* counter for message id */
		public $keepalive = 10;		/* default keepalive timmer */
		public $timesinceping;		/* host unix time, used to detect disconects */
		public $topics = array(); 	/* used to store currently subscribed topics */
		public $debug = false;		/* should output debug messages */
		public $address;			/* broker address */
		public $port;				/* broker port */
		public $clientid;			/* client id sent to brocker */
		public $will;				/* stores the will of the client */
		private $username;			/* stores username */
		private $password;			/* stores password */
		function __construct($address, $port, $clientid){
			$this->broker($address, $port, $clientid);
		}
		/* sets the broker details */
		function broker($address, $port, $clientid){
			$this->address = $address;
			$this->port = $port;
			$this->clientid = $clientid;		
		}
		function connect_auto($clean = true, $will = NULL, $username = NULL, $password = NULL){
			while($this->connect($clean, $will, $username, $password)==false){
				sleep(10);
			}
			return true;
		}
		/* connects to the broker 
			inputs: $clean: should the client send a clean session flag */
		function connect($clean = true, $will = NULL, $username = NULL, $password = NULL){
			
			if($will) $this->will = $will;
			if($username) $this->username = $username;
			if($password) $this->password = $password;
			$address = gethostbyname($this->address);	
			$this->socket = fsockopen($address, $this->port, $errno, $errstr, 60);
			if (!$this->socket ) {
			    if($this->debug) error_log("fsockopen() $errno, $errstr \n");
				return false;
			}
			stream_set_timeout($this->socket, 5);
			stream_set_blocking($this->socket, 0);
			$i = 0;
			$buffer = "";
			$buffer .= chr(0x00); $i++;
			$buffer .= chr(0x06); $i++;
			$buffer .= chr(0x4d); $i++;
			$buffer .= chr(0x51); $i++;
			$buffer .= chr(0x49); $i++;
			$buffer .= chr(0x73); $i++;
			$buffer .= chr(0x64); $i++;
			$buffer .= chr(0x70); $i++;
			$buffer .= chr(0x03); $i++;
			//No Will
			$var = 0;
			if($clean) $var+=2;
			//Add will info to header
			if($this->will != NULL){
				$var += 4; // Set will flag
				$var += ($this->will['qos'] << 3); //Set will qos
				if($this->will['retain'])	$var += 32; //Set will retain
			}
			if($this->username != NULL) $var += 128;	//Add username to header
			if($this->password != NULL) $var += 64;	//Add password to header
			$buffer .= chr($var); $i++;
			//Keep alive
			$buffer .= chr($this->keepalive >> 8); $i++;
			$buffer .= chr($this->keepalive & 0xff); $i++;
			$buffer .= $this->strwritestring($this->clientid,$i);
			//Adding will to payload
			if($this->will != NULL){
				$buffer .= $this->strwritestring($this->will['topic'],$i);  
				$buffer .= $this->strwritestring($this->will['content'],$i);
			}
			if($this->username) $buffer .= $this->strwritestring($this->username,$i);
			if($this->password) $buffer .= $this->strwritestring($this->password,$i);
			$head = "  ";
			$head{0} = chr(0x10);
			$head{1} = chr($i);
			fwrite($this->socket, $head, 2);
			fwrite($this->socket,  $buffer);
		 	$string = $this->read(4);
			if(ord($string{0})>>4 == 2 && $string{3} == chr(0)){
				if($this->debug) echo "Connected to Broker\n"; 
			}else{	
				error_log(sprintf("Connection failed! (Error: 0x%02x 0x%02x)\n", 
				                        ord($string{0}),ord($string{3})));
				return false;
			}
			$this->timesinceping = time();
			return true;
		}
		/* read: reads in so many bytes */
		function read($int = 8192, $nb = false){
			//	print_r(socket_get_status($this->socket));
			
			$string="";
			$togo = $int;
			
			if($nb){
				return fread($this->socket, $togo);
			}
				
			while (!feof($this->socket) && $togo>0) {
				$fread = fread($this->socket, $togo);
				$string .= $fread;
				$togo = $int - strlen($string);
			}
			
		
			
			
				return $string;
		}
		/* subscribe: subscribes to topics */
		function subscribe($topics, $qos = 0){
			$i = 0;
			$buffer = "";
			$id = $this->msgid;
			$buffer .= chr($id >> 8);  $i++;
			$buffer .= chr($id % 256);  $i++;
			foreach($topics as $key => $topic){
				$buffer .= $this->strwritestring($key,$i);
				$buffer .= chr($topic["qos"]);  $i++;
				$this->topics[$key] = $topic; 
			}
			$cmd = 0x80;
			//$qos
			$cmd +=	($qos << 1);
			$head = chr($cmd);
			$head .= chr($i);
			
			fwrite($this->socket, $head, 2);
			fwrite($this->socket, $buffer, $i);
			$string = $this->read(2);
			
			$bytes = ord(substr($string,1,1));
			$string = $this->read($bytes);
		}
		/* ping: sends a keep alive ping */
		function ping(){
				$head = " ";
				$head = chr(0xc0);		
				$head .= chr(0x00);
				fwrite($this->socket, $head, 2);
				if($this->debug) echo "ping sent\n";
		}
		/* disconnect: sends a proper disconect cmd */
		function disconnect(){
				$head = " ";
				$head{0} = chr(0xe0);		
				$head{1} = chr(0x00);
				fwrite($this->socket, $head, 2);
		}
		/* close: sends a proper disconect, then closes the socket */
		function close(){
		 	$this->disconnect();
			fclose($this->socket);	
		}
		/* publish: publishes $content on a $topic */
		function publish($topic, $content, $qos = 0, $retain = 0){
			$i = 0;
			$buffer = "";
			$buffer .= $this->strwritestring($topic,$i);
			//$buffer .= $this->strwritestring($content,$i);
			if($qos){
				$id = $this->msgid++;
				$buffer .= chr($id >> 8);  $i++;
			 	$buffer .= chr($id % 256);  $i++;
			}
			$buffer .= $content;
			$i+=strlen($content);
			$head = " ";
			$cmd = 0x30;
			if($qos) $cmd += $qos << 1;
			if($retain) $cmd += 1;
			$head{0} = chr($cmd);		
			$head .= $this->setmsglength($i);
			fwrite($this->socket, $head, strlen($head));
			fwrite($this->socket, $buffer, $i);
		}
		/* message: processes a recieved topic */
		function message($msg){
			 	$tlen = (ord($msg{0})<<8) + ord($msg{1});
				$topic = substr($msg,2,$tlen);
				$msg = substr($msg,($tlen+2));
				$found = 0;
				foreach($this->topics as $key=>$top){
					if( preg_match("/^".str_replace("#",".*",
							str_replace("+","[^\/]*",
								str_replace("/","\/",
									str_replace("$",'\$',
										$key))))."$/",$topic) ){
						if(is_callable($top['function'])){
							call_user_func($top['function'],$topic,$msg);
							$found = 1;
						}
					}
				}
				if($this->debug && !$found) echo "msg recieved but no match in subscriptions\n";
		}
		/* proc: the processing loop for an "allways on" client 
			set true when you are doing other stuff in the loop good for watching something else at the same time */	
		function proc( $loop = true){
			if(1){
				$sockets = array($this->socket);
				$w = $e = NULL;
				$cmd = 0;
				
					//$byte = fgetc($this->socket);
				if(feof($this->socket)){
					if($this->debug) echo "eof receive going to reconnect for good measure\n";
					fclose($this->socket);
					$this->connect_auto(false);
					if(count($this->topics))
						$this->subscribe($this->topics);	
				}
				
				$byte = $this->read(1, true);
				
				if(!strlen($byte)){
					if($loop){
						usleep(100000);
					}
				 
				}else{ 
				
					$cmd = (int)(ord($byte)/16);
					if($this->debug) echo "Recevid: $cmd\n";
					$multiplier = 1; 
					$value = 0;
					do{
						$digit = ord($this->read(1));
						$value += ($digit & 127) * $multiplier; 
						$multiplier *= 128;
						}while (($digit & 128) != 0);
					if($this->debug) echo "Fetching: $value\n";
					
					if($value)
						$string = $this->read($value,"fetch");
					
					if($cmd){
						switch($cmd){
							case 3:
								$this->message($string);
							break;
						}
						$this->timesinceping = time();
					}
				}
				if($this->timesinceping < (time() - $this->keepalive )){
					if($this->debug) echo "not found something so ping\n";
					$this->ping();	
				}
				
				if($this->timesinceping<(time()-($this->keepalive*2))){
					if($this->debug) echo "not seen a package in a while, disconnecting\n";
					fclose($this->socket);
					$this->connect_auto(false);
					if(count($this->topics))
						$this->subscribe($this->topics);
				}
			}
			return 1;
		}
		/* getmsglength: */
		function getmsglength(&$msg, &$i){
			$multiplier = 1; 
			$value = 0 ;
			do{
			  $digit = ord($msg{$i});
			  $value += ($digit & 127) * $multiplier; 
			  $multiplier *= 128;
			  $i++;
			}while (($digit & 128) != 0);
			return $value;
		}
		/* setmsglength: */
		function setmsglength($len){
			$string = "";
			do{
			  $digit = $len % 128;
			  $len = $len >> 7;
			  // if there are more digits to encode, set the top bit of this digit
			  if ( $len > 0 )
			    $digit = ($digit | 0x80);
			  $string .= chr($digit);
			}while ( $len > 0 );
			return $string;
		}
		/* strwritestring: writes a string to a buffer */
		function strwritestring($str, &$i){
			$ret = " ";
			$len = strlen($str);
			$msb = $len >> 8;
			$lsb = $len % 256;
			$ret = chr($msb);
			$ret .= chr($lsb);
			$ret .= $str;
			$i += ($len+2);
			return $ret;
		}
		function printstr($string){
			$strlen = strlen($string);
				for($j=0;$j<$strlen;$j++){
					$num = ord($string{$j});
					if($num > 31) 
						$chr = $string{$j}; else $chr = " ";
					printf("%4d: %08b : 0x%02x : %s \n",$j,$num,$num,$chr);
				}
		}
	}
	$host = "www.km1.io";
	$port = "1883";
	$username = "iw53154";
	$password = "iw49050222";
	$topic = "/iw53154/room1";
	$access_token = 'YkkdgSiv7BLGLWQXoyNXPfkRrRAUd4DzjLy7icTetC7IaFml/EaqTnS83l0EM0OaZ/pd6bIzW4HkXIWzEBXT0wG7A7W4h2YIgu1Qi+IU1MNrxOz45cQC52QqjejK8SfQazkcN2mz2HLefiAsz1jgAQdB04t89/1O/w1cDnyilFU=';
	
	// Get POST body content
	$content = file_get_contents('php://input');
	// Parse JSON
	$events = json_decode($content, true);
	// Validate parsed JSON data
	if (!is_null($events['events'])) {
		// Loop through each event
		foreach ($events['events'] as $event) {
			// Reply only when message sent is in 'text' format
			if ($event['type'] == 'message' && $event['message']['type'] == 'text') {
				// Get text sent
				$text = $event['message']['text'];
				// Get replyToken
				$replyToken = $event['replyToken'];
				if ($text == "สวัสดี" || $text == "ดีจ้า" || $text == "hello"){
					$A = array("สวัสดี สบายดีไหม???","สวัสดีจ้าา","ว่าไงมีไรให้ผมช่วย");
					$B = array_rand($A,1);
					$text= $A[$B];
				}
				if ($text == "เป็นไงบ่าง"|| $text == "สบายดีไหม"){
					$A1 = array("สบายดี","ไม่ค่อยสบายเลย");
					$B1 = array_rand($A1,1);
					$text = $A1[$B1];
				}

				// Build message to reply back
				$messages = [
					'type' => 'text',
					'text' => $text
				];
				$mqtt = new phpMQTT($host, $port, "iftt.php".rand());
				if ($mqtt->connect(true, NULL, $username, $password)) {
					$mqtt->publish($topic, $text, 0, true);
					$mqtt->close();
				}
	
	
				// Make a POST Request to Messaging API to reply to sender
				$url = 'https://api.line.me/v2/bot/message/reply';
				$data = [
					'replyToken' => $replyToken,
					'messages' => [$messages],
				];
				$post = json_encode($data);
				$headers = array('Content-Type: application/json', 'Authorization: Bearer ' . $access_token);
	
				$ch = curl_init($url);
				curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
				curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
				curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
				$result = curl_exec($ch);
				curl_close($ch);
	
				echo $result . "\r\n";
			}
		}
	}
	echo "OK";
