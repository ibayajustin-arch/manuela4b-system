<?php
require_once 'config/db.php';

// The credentials we want to create
$username = 'resident01';
$password = 'resident123'; // The plain text password
$full_name = 'Juan Dela Cruz';
$role = 'resident'; // This triggers the redirect to the resident folder!

// Hash the password securely
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

try {
    // Check if the user already exists to prevent duplicates
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    
    if ($stmt->fetch()) {
        echo "Account already exists! You can log in with username: <b>resident01</b> and password: <b>resident123</b>";
    } else {
        // Insert the new resident into the database
        $insert = $pdo->prepare("INSERT INTO users (username, password, full_name, role) VALUES (?, ?, ?, ?)");
        $insert->execute([$username, $hashed_password, $full_name, $role]);
        
        echo "<h3 style='color: green;'>Success! Test Resident Created.</h3>";
        echo "<p><b>Username:</b> resident01</p>";
        echo "<p><b>Password:</b> resident123</p>";
        echo "<p><a href='login.php'>Go to Login</a></p>";
    }
} catch (PDOException $e) {
    echo "Error creating user: " . $e->getMessage();
}
?>