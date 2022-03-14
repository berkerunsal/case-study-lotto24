<?php

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
include_once 'lotto-products.php';

$lotto_product = new Lotto_Product();

$data = json_decode(file_get_contents("php://input"), 1);

if (!empty($data["productId"]) && !empty($data["displayName"]) && !empty($data["apiKey"])) {
    $product_id = $data["productId"];

    if (apply_filters('is_product_exist', $product_id) == true) {
        echo json_encode(array("message" => "Product with this productId already exists."));

    } else {
        if (!empty($data["productIcon"])) {
            $icon = $lotto_product->get_image($data["productIcon"]);
            $data["productIcon"] = $icon;

        }
        $lotto_product->save_product($data);
        echo json_encode(array("message" => "The product has been successfully added."));
        http_response_code(201);

    }
} else {
    echo json_encode(array("message" => "Unable to create product. At least one field is missing."));
}
