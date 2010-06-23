<?php
/*
 * @Author: Ilia Alshanetsky
 * @email: ilia at ilia dot ws
 */
/*
Copyright (c) 2010, Ilia Alshanetsky
All rights reserved.

Redistribution and use in source and binary forms, with or without
modification, are permitted provided that the following conditions are met:
	* Redistributions of source code must retain the above copyright
	  notice, this list of conditions and the following disclaimer.
	* Redistributions in binary form must reproduce the above copyright
      notice, this list of conditions and the following disclaimer in the
      documentation and/or other materials provided with the distribution.
    * Neither the name of the <organization> nor the
      names of its contributors may be used to endorse or promote products
      derived from this software without specific prior written permission.
                                          
THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
DISCLAIMED. IN NO EVENT SHALL Ilia Alshanetsky BE LIABLE FOR ANY
DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
(INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
(INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
*/

set_time_limit(-1);
define("GDOCS_BACKUP_DIR", "./docs/");

if (!is_dir(GDOCS_BACKUP_DIR)) {
	if (!mkdir(GDOCS_BACKUP_DIR, 0755)) {
		exit("Docs backup directory '".GDOCS_BACKUP_DIR."' does not exist and could not be created\n");
	}
} else if (!is_writable(GDOCS_BACKUP_DIR)) {
	exit("The backup dir '".GDOCS_BACKUP_DIR."' is not writable\n");
}

$curl = curl_init();
curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

function get_auth_token($curl, $type = "writely") {
	// Construct an HTTP POST request
	$auth = array(
		"accountType" => 'HOSTED_OR_GOOGLE',
		"Email" => '',
		"Passwd" => '',
		"service" => $type,
		"source" => 'Document Backup App'
	);

	curl_setopt($curl, CURLOPT_URL, "https://www.google.com/accounts/ClientLogin");
	curl_setopt($curl, CURLOPT_POST, true);
	curl_setopt($curl, CURLOPT_POSTFIELDS, $auth);

	$response = curl_exec($curl);

	// Get the Auth string and save it
	if (preg_match("/Auth=([a-z0-9_\-]+)/i", $response, $matches)) {
		return $matches[1];
	} else {
		exit("No token\n");
	}
}

$auth = get_auth_token($curl);

$headers_docs = array(
	"Authorization: GoogleLogin auth=" . $auth,
	"GData-Version: 3.0",
);

$auth = get_auth_token($curl, "wise");

$headers_xls = array(
	"Authorization: GoogleLogin auth=" . $auth,
	"GData-Version: 3.0",
);

curl_setopt($curl, CURLOPT_URL, "https://docs.google.com/feeds/default/private/full");
curl_setopt($curl, CURLOPT_HTTPHEADER, $headers_docs);
curl_setopt($curl, CURLOPT_POST, false);

$data = curl_exec($curl);
if (curl_getinfo($curl, CURLINFO_HTTP_CODE) == 200) {
	$response = simplexml_load_string($data);
} else {
	exit("ERROR: could not obtain document list\n");
}

$backup_date = date("Ymd");
$start_date = time() - 86400;

$first_run = file_exists(GDOCS_BACKUP_DIR . '.firstrun');

// Data itteration
foreach($response->entry as $file) {
	// Backup all files on first run
	if ($first_run) {
		if (!$file->updated) {
			$tm = strtotime($file->published);
		} else {
			$tm = strtotime($file->updated);
		}

		// file already backed up, we only backup files updated/created in the last 24 hours
		if ($start_date > $tm) {
			continue;
		}
	}

	// establish output content type
	$host = strtok(parse_url($file->content['src'], PHP_URL_HOST), '.');
	switch ($host) {
		case 'docs':
			if (strpos($file->content['src'], '/presentations/') !== false) {
				$format = 'ppt';
			} else {
				$format = 'doc';
			}
			break;
		case 'spreadsheets':
			$format = 'xls';	
			break;
		case 'presentations':
		case 'present':
			$format = 'ppt';
			break;
		default:
			$format = 'pdf'; // safe default, since Google can export anything to PDF
			break;
	}

	// for Excel files we need to use a different authentication token
	if ($format != 'xls') {
		curl_setopt($curl, CURLOPT_HTTPHEADER, $headers_docs);
	} else {
		curl_setopt($curl, CURLOPT_HTTPHEADER, $headers_xls);
	}

	$cur_day_dir = GDOCS_BACKUP_DIR . '/' . $backup_date . '/';
	if (!is_dir($cur_day_dir)) {
		if (!mkdir($cur_day_dir, 0755)) {
			exit("Could not create backup directory");
		}
	}

	curl_setopt($curl, CURLOPT_URL, $file->content['src'] . '&exportFormat=' . $format);
	$data = curl_exec($curl);
	if (curl_getinfo($curl, CURLINFO_HTTP_CODE) == 200) {
		if (extension_loaded('zip')) {
			$zip = new ZipArchive();
			if ($zip->open($cur_day_dir . strtr($file->title, ' ', '_') . '.zip', ZIPARCHIVE::CREATE) !== TRUE) {
				exit("ZIP archive creation failure!\n");
			}
			$zip->addFromString(strtr($file->title, ' ', '_') . '.' . $format, $data);
			$zip->close();
		} else {
			file_put_contents($cur_day_dir . strtr($file->title, ' ', '_') . '.' . $format, $data);
		}
	} else {
		echo "ERROR: could not retrieve " . $file->title .  "\n";
	}
}
curl_close($curl);

if (!$first_run) {
	touch(GDOCS_BACKUP_DIR . '.firstrun');
}
