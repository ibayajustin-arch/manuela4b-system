<?php

$host = "localhost";
$username = "root";
$password = "";
$database = "manuela4b_mis";

$conn = mysqli_connect(
    $host,
    $username,
    $password,  
    $database
);

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

?>
