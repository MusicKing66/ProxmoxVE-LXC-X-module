<?php
function getPdoConnection(): PDO {
    return new PDO('mysql:host=localhost;dbname=sql_miraigrid_co;charset=utf8mb4', 'sql_miraigrid_co', '60581a778fdf', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
}
