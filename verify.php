<?php
$access_token = 'YkkdgSiv7BLGLWQXoyNXPfkRrRAUd4DzjLy7icTetC7IaFml/EaqTnS83l0EM0OaZ/pd6bIzW4HkXIWzEBXT0wG7A7W4h2YIgu1Qi+IU1MNrxOz45cQC52QqjejK8SfQazkcN2mz2HLefiAsz1jgAQdB04t89/1O/w1cDnyilFU=';

$url = 'https://api.line.me/v1/oauth/verify';

$headers = array('Authorization: Bearer ' . $access_token);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
$result = curl_exec($ch);
curl_close($ch);

echo $result;
