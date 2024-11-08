<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Manila');

include('../config/function.php');

if(!isset($_SESSION['productItems'])) {
    $_SESSION['productItems'] = [];
}

if(!isset($_SESSION['productItemIds'])) {
    $_SESSION['productItemIds'] = [];
}

// Function to get UoM ratio by unit_id
function getUomRatio($conn, $unit_id) {
    $query = "SELECT ratio FROM units_of_measure WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $unit_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    return $result ? $result['ratio'] : null; // Return the ratio or null
}

// Function to update ingredient inventory
function updateIngredientInventory($conn, $productId, $orderQuantity) {
    // Get the recipe ID for the product
    $recipeQuery = "SELECT id FROM recipes WHERE product_id = ?";
    $stmt = $conn->prepare($recipeQuery);
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $recipeResult = $stmt->get_result();
    
    if ($recipeRow = $recipeResult->fetch_assoc()) {
        $recipeId = $recipeRow['id'];

        // Get the ingredients required for the recipe
        $ingredientQuery = "SELECT ingredient_id, quantity, unit_id FROM recipe_ingredients WHERE recipe_id = ?";
        $ingredientStmt = $conn->prepare($ingredientQuery);
        $ingredientStmt->bind_param("i", $recipeId);
        $ingredientStmt->execute();
        $ingredientResult = $ingredientStmt->get_result();

        while ($ingredientRow = $ingredientResult->fetch_assoc()) {
            $ingredientId = $ingredientRow['ingredient_id'];
            $quantityRequired = $ingredientRow['quantity'] * $orderQuantity; // Total required for the order
            
            // Get the unit ratio for the ingredient
            $unitId = $ingredientRow['unit_id'];
            $unitQuery = "SELECT ratio FROM units_of_measure WHERE id = ?";
            $unitStmt = $conn->prepare($unitQuery);
            $unitStmt->bind_param("i", $unitId);
            $unitStmt->execute();
            $unitResult = $unitStmt->get_result()->fetch_assoc();
            
            if ($unitResult) {
                $ratio = $unitResult['ratio'];

                // Convert the required quantity to the base unit (assuming base unit is grams or similar)
                $quantityRequiredInBase = $quantityRequired * $ratio;

                // Deduct the ingredient quantity
                $currentQuantityQuery = "SELECT quantity FROM ingredients WHERE id = ?";
                $currentStmt = $conn->prepare($currentQuantityQuery);
                $currentStmt->bind_param("i", $ingredientId);
                $currentStmt->execute();
                $currentQuantityResult = $currentStmt->get_result()->fetch_assoc();

                if ($currentQuantityResult) {
                    $currentQuantity = $currentQuantityResult['quantity'];

                    if ($currentQuantity >= $quantityRequiredInBase) {
                        // Update the inventory
                        $updateInventoryQuery = "UPDATE ingredients SET quantity = quantity - ? WHERE id = ?";
                        $updateStmt = $conn->prepare($updateInventoryQuery);
                        $updateStmt->bind_param("di", $quantityRequiredInBase, $ingredientId);
                        $updateStmt->execute();
                    } else {
                        // Handle insufficient stock (optional)
                        $_SESSION['error'] = "Insufficient stock for ingredient ID: $ingredientId";
                    }
                }
            }
        }
    } else {
        $_SESSION['error'] = "Recipe not found for product ID: $productId";
    }
}


if(isset($_POST['addItem'])){
    $productId = validate($_POST['product_id']);
    $quantity = validate($_POST['quantity']);

    $checkProduct = mysqli_query($conn, "SELECT * FROM products WHERE id='$productId' LIMIT 1");
    if($checkProduct){
        if(mysqli_num_rows($checkProduct)>0){
            $row = mysqli_fetch_assoc($checkProduct);
            if($row['quantity'] < $quantity){
                redirect('order-create.php', 'Only ' .$row['quantity']. ' ' .$row['productname']. ' available.');
            }

            $productData = [
                'product_id' => $row['id'],
                'name' => $row['productname'],
                'image' => $row['image'],
                'price' => $row['price'],
                'quantity' => $quantity,
            ];

            if(!in_array($row['id'], $_SESSION['productItemIds'])){
                array_push($_SESSION['productItemIds'],$row['id']);
                array_push($_SESSION['productItems'],$productData);
            }else{
                foreach($_SESSION['productItems'] as $key => $prodSessionItem) {
                    if($prodSessionItem['product_id'] == $row['id']){
                        $newQuantity = $prodSessionItem['quantity'] + $quantity;

                        $productData = [
                            'product_id' => $row['id'],
                            'name' => $row['productname'],
                            'image' => $row['image'],
                            'price' => $row['price'],
                            'quantity' => $newQuantity,
                        ];

                        $_SESSION['productItems'][$key] = $productData;
                    }
                }
            }
            redirect('order-create.php', 'Item added: ' .$quantity. ' ' .$row['productname']);
        } else {
            redirect('order-create.php', 'No such product found!');
        }
    } else {
        redirect('order-create.php','Something went wrong!');
    }
}

if(isset($_POST['productIncDec'])) {
    $productId = validate($_POST['product_id']);
    $quantity = validate($_POST['quantity']);

    // Fetch product details from the database
    $checkProduct = mysqli_query($conn, "SELECT * FROM products WHERE id='$productId' LIMIT 1");

    // Initialize flags
    $flag = false;
    $flag1 = false;

    // Check if product exists
    if (mysqli_num_rows($checkProduct) > 0) {
        $row = mysqli_fetch_assoc($checkProduct);
            // Check against session items
            foreach($_SESSION['productItems'] as $key => $item) {
                if ($item['product_id'] == $productId) {
                    // Check if requested quantity exceeds available quantity
                    if ($row['quantity'] < $quantity + 1) {
                        $flag1 = true; // Not enough available
                    } else {
                        // Enough available, update the quantity
                        $_SESSION['productItems'][$key]['quantity'] = $quantity;
                        $flag = true; // Quantity changed
                    }
                    break; // Break once we find the item
                }
            }
        
    }
    if ($flag1) {
        jsonResponse(500, 'success', "Maximum quantity reached!");
    } else if ($flag) {
        jsonResponse(200, 'success', "Quantity changed.");
    } else {
        jsonResponse(500, 'error', "Something went wrong!");
    }
}

if (isset($_POST['proceedToPlaceBtn'])) {
    $name = validate($_POST['cname']);
    $payment_mode = validate($_POST['payment_mode']);
    $order_status = validate($_POST['order_status']);

    $checkCustomer = mysqli_query($conn, "SELECT * FROM customers WHERE name='$name' LIMIT 1");

    if ($checkCustomer) {
        if (mysqli_num_rows($checkCustomer) > 0) {
            $_SESSION['invoice_no'] = "INV-" .rand(111111, 999999);
            $_SESSION['cname'] = $name;
            $_SESSION['payment_mode'] = $payment_mode;
            $_SESSION['order_status'] = $order_status;

            jsonResponse(200, 'success', 'Customer found');
        } else {
            $_SESSION['cname'] = $name;
            jsonResponse(404, 'warning', 'Customer not found');
        }
    } else {
        jsonResponse(500, 'error', 'Something Went Wrong');
    }
}

if (isset($_POST['proceedToUpdateBtn'])) {
    $order_status = validate($_POST['order_status']);
    $order_id = validate($_POST['order_id']);

    // Debugging: print the order ID and the order data
    error_log("Order ID: " . $order_id);
    $orderData = getByID('orders', $order_id);
    error_log(print_r($orderData, true));

    if ($orderData['status'] != 200) {
        jsonResponse(404, 'error', 'Order not found');
        exit();
    }

    if ($order_status != '') {
        $data = ['order_status' => $order_status];
        $updateResult = update('orders', $order_id, $data);

        if ($updateResult) {
            jsonResponse(200, 'success', 'Order updated successfully');
        } else {
            jsonResponse(500, 'error', 'Failed to update order status');
        }
    } else {
        jsonResponse(400, 'error', 'Invalid order ID or status');
    }
}

if (isset($_POST['proceedToCompleteBtn'])) {
    // Check what POST data is received
    error_log(print_r($_POST, true)); // Log POST data for debugging

    $order_status = validate($_POST['order_status'] ?? ''); // Use null coalescing to avoid undefined index
    $order_track = validate($_POST['order_track'] ?? ''); // Use null coalescing to avoid undefined index

    if (empty($order_track)) {
        jsonResponse(400, 'error', 'Invalid order tracking number');
        exit();
    }

    // Fetch the order ID based on the tracking number
    $query = "SELECT id FROM orders WHERE tracking_no = '$order_track' LIMIT 1";
    $result = mysqli_query($conn, $query);
    
    // Check for query error
    if (!$result) {
        jsonResponse(500, 'error', 'Database query failed: ' . mysqli_error($conn));
        exit();
    }

    $orderData = mysqli_fetch_assoc($result);

    if ($orderData) {
        $order_id = $orderData['id']; // Get the order ID

        $data = ['order_status' => $order_status];
        $updateResult = update('orders', $order_id, $data);

        if ($updateResult) {
            jsonResponse(200, 'success', 'Order updated successfully');
        } else {
            jsonResponse(500, 'error', 'Failed to update order status');
        }
    } else {
        jsonResponse(404, 'error', 'Order not found');
    }
}

if(isset($_POST['saveCustomerBtn'])){
    $name = validate($_POST['name']);  // Change 'c_name' to 'name'

    if($name != ''){
       $data = [
            'name' => $name
        ];

        $result = insert('customers', $data);

        if($result){
            jsonResponse(200, 'success', 'Customer Added Successfully!');
        }else{
            jsonResponse(500, 'error', 'Something Went Wrong');
        }
    }else{
        jsonResponse(422, 'warning', 'Please fill required fields');
    }
}

if (isset($_POST['saveOrder'])) {
    $name = validate($_SESSION['cname']);
    $invoice_no = validate($_SESSION['invoice_no']);
    $payment_mode = validate($_SESSION['payment_mode']);
    $order_placed_by_id = validate($_SESSION['loggedInUser']['firstname']);
    $order_status = validate($_SESSION['order_status']);

    // Check if customer exists
    $checkCustomer = mysqli_query($conn, "SELECT * FROM customers WHERE name='$name' LIMIT 1");
    if (!$checkCustomer) {
        jsonResponse(500, 'error', 'Something Went Wrong');
    }

    if (mysqli_num_rows($checkCustomer) > 0) {
        $customerData = mysqli_fetch_assoc($checkCustomer);

        if (!isset($_SESSION['productItems'])) {
            jsonResponse(404, 'warning', 'No items to place order');
            exit;
        }

        $totalAmount = 0;
        $sessionProducts = $_SESSION['productItems'];  // Make sure this exists
        foreach ($sessionProducts as $amtItem) {
            $totalAmount += $amtItem['price'] * $amtItem['quantity'];  // Fix typo
        }

        $data = [
            'customer_id' => $customerData['id'],
            'tracking_no' => rand(111111, 999999),
            'invoice_no' => $invoice_no,
            'total_amount' => $totalAmount,
            'order_date' => date('Y-m-d H:i:s'),
            'order_status' => $order_status,
            'payment_mode' => $payment_mode,
            'order_placed_by_id' => $order_placed_by_id
        ];

        $result = insert('orders', $data);
        $lastOrderId = mysqli_insert_id($conn);

        // Call the updateIngredientInventory function here
        // updateIngredientInventory($conn, $productId, $quantity); // Pass the necessary product ID and quantity

        foreach ($sessionProducts as $prodItem) {
            $productId = $prodItem['product_id'];
            $price = $prodItem['price'];
            $quantity = $prodItem['quantity'];

            $dataOrderItem = [
                'order_id' => $lastOrderId,
                'product_id' => $productId,
                'price' => $price,
                'quantity' => $quantity,
            ];

            $orderItemQuery = insert('order_items', $dataOrderItem);

            // Update product quantities
            $checkProductQuantityQuery = mysqli_query($conn, "SELECT * FROM products WHERE id='$productId'");
            $productQtyData = mysqli_fetch_assoc($checkProductQuantityQuery);
            $totalProductsQuantity = $productQtyData['quantity'] - $quantity;

            $dataUpdate = [
                'quantity' => $totalProductsQuantity
            ];

            $updateProductQty = update('products', $productId, $dataUpdate);

            // Update ingredient inventory based on the recipe
            updateIngredientInventory($conn, $productId, $quantity); // Call the function here
        }

        unset($_SESSION['productItemIds'], $_SESSION['productItems'], $_SESSION['payment_mode'], $_SESSION['invoice_no']);
        jsonResponse(200, 'success', 'Order placed successfully!');
    } else {
        jsonResponse(404, 'warning', 'No Customer found');
    }
}

?>
