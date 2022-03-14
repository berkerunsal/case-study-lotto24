<?php

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
include_once 'lotto-products.php';

$lotto_product = new Lotto_Product();

$data = json_decode(file_get_contents("php://input"), 1);

if (!empty($data["fileType"]) && !empty($data["fileUrl"])) {
    $fileType = $data["fileType"];
    $fileUrl = $data["fileUrl"];
    $fileName = basename($fileUrl);
    $icons = [
        ["productIcon" => $fileName],

    ];

    if ($fileType != "productIcon") {
        echo json_encode(array("message" => "Unsupportded file type."));
    } else {
        $request = wp_remote_get($fileUrl);
        $response_code = wp_remote_retrieve_response_code($request);

        if ($response_code != 200) {
            echo json_encode(array("message" => "File doesn't exist."));
        } else {

            $destination = plugin_dir_path(__FILE__) . 'assets/icons/' . $fileName;
            set_time_limit(0);
            $source = $fileUrl;
            $timeout = 30;
            $fh = fopen($destination, "w");
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $source);
            curl_setopt($ch, CURLOPT_FILE, $fh);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

            curl_exec($ch);
            if (curl_errno($ch)) {
                echo "CURL ERROR - " . curl_error($ch);
            } else {
                $hash = $lotto_product->init_icon($icons);
                echo json_encode(array("message" => "Icon has been created."));
                echo json_encode(array("hash" => $hash));
            }

            curl_close($ch);
            fclose($fh);

        }
    }

} else {
    echo json_encode(array("message" => "Unable to create icon. At least one field is missing."));
}
