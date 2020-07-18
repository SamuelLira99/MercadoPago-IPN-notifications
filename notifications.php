<?php

    // these includes has nothing to do with the notification proccess itself
    $root = $_SERVER['DOCUMENT_ROOT'];

    include "$root/api/connection.php";         // just a script that connects to database
    include "$root/api/constants.php";          // just a file that hold some constants used here
    include "$root/inc/extend-signature.php";   // just a file that provides some functions for my website


    /*****************************************************************/
    /********** The important thing begins from here bellow **********/
    /*****************************************************************/


    /*
    the only really required thing to get a notification is that variable $_GET['id']
    this is the 'merchant order id'
    we will get info about each payment by POSTing this id to MercadoPago servers via cURL
    */

    // Step 1 -> Store the 'merchant order id' in a variable
    $id = $_GET['id']; // the only really required variable to receive the notification


    // Step 2 -> do a POST request using this 'id' that we stored and your MercadoPago 'access token' as parameters
    $curl = curl_init();

    curl_setopt($curl, CURLOPT_URL, 'https://api.mercadopago.com//merchant_orders/'.$id.'?access_token='.$MP_PRODUCTION_ACCESS_TOKEN);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

    // Step 2.5 -> Store response from MercadoPago server into a variable
    $result = json_decode(curl_exec($curl), true);

    if (curl_errno($curl)) {
        echo 'Error:' . curl_error($curl);
    }

    curl_close($curl);


    // Step 3 -> Do whatever you need with the response data

    /*
    this is what I used on my website, take this as an example
    so you can understand better how MercadoPago send the response

    from here bellow is some code I used for my website
    it's not instructions, just examples, and the comments are just
    explaining what's happening to code itself, not about the notification proccess

    It's an implementation that extends a signature for a user
    when the payment becomes appoved by MercadoPago
    */

    // Split cURL response into variables
    $merchant_order_id = $id;
    $transaction_amount = $result["payments"][0]["transaction_amount"];
    $date_created = $result["payments"][0]["date_created"];
    $date_last_updated = $result["payments"][0]["last_modified"];
    $status = $result["payments"][0]["status"];
    $status_detail = $result["payments"][0]["status_detail"];
    $collector_id = $result["collector"]["id"];

    // Format dates
    $date_created = new DateTime($date_created);
    $date_created = $date_created->format("Y-m-d H:i:s");

    $date_last_updated = new DateTime($date_last_updated);
    $date_last_updated = $date_last_updated->format("Y-m-d H:i:s");

    // Connect to database and perform queries
    $conn = getConnection($RENT_YOUR_APP_DB);

    $query = "SELECT * FROM $RENT_YOUR_APP_TABLE_PAYMENTS WHERE merchant_order_id = '$id'";

    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $res_count = count($result);

    if($res_count < 1) {
        $query = "INSERT INTO $RENT_YOUR_APP_TABLE_PAYMENTS(
            merchant_order_id,
            transaction_amount,
            status,
            status_detail,
            date_created,
            date_last_updated,
            collector_id
        ) VALUES(
            $merchant_order_id,
            $transaction_amount,
            '$status',
            '$status_detail',
            '$date_created',
            '$date_last_updated',
            '$collector_id'
        )";
    } else if($res_count == 1) {
        $query = "UPDATE $RENT_YOUR_APP_TABLE_PAYMENTS
            set transaction_amount = $transaction_amount,
            status = '$status',
            status_detail = '$status_detail',
            date_created = '$date_created',
            date_last_updated = '$date_last_updated',
            collector_id = '$collector_id'
            WHERE merchant_order_id = '$merchant_order_id'";
    } else {
        die("SQL querry error");
    }

    $stmt = $conn->prepare($query);
    $query_status = $stmt->execute() ? "Payment updated successfully<br>" : "Failed to update payment<br>";

    if($res_count == 1 && $status == 'approved' && $status_detail == 'accredited') {
        $query = "SELECT * FROM $RENT_YOUR_APP_TABLE_PAYMENTS WHERE merchant_order_id = '$id'";

        $stmt = $conn->prepare($query);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if(count($result) == 1) {
            $current_user_id = $result[0]["rya_user_id"];
            extendSignature($current_user_id);
        }
    }
