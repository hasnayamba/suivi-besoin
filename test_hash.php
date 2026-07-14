<?php

$hash = '$2y$10$thqtL8DJdYytq94CLY61zedm5jcq/zndFHOZZV1pZT7TNUUPnG1hy';

echo "<pre>";
var_dump(password_verify('123', $hash));
echo "</pre>";