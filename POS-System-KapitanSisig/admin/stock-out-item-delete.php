<?php
require '../config/function.php';

$paramResult = checkParam('index');

if(is_numeric($paramResult)){

    $indexValue = validate($paramResult);

    if(isset($_SESSION['soItems']) && isset($_SESSION['soItemIds'])){
        unset($_SESSION['soItems'][$indexValue]);
        unset($_SESSION['soItemIds'][$indexValue]);

        redirect('stock-out-create.php', 'Item Removed!');
    }else{
        redirect('stock-out-create.php', 'There is no item.');
    }

}else{
    redirect('stock-out-create.php', 'param not numeric');
}
?>