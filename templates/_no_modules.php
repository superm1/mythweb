<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01//EN" "http://www.w3.org/TR/html4/strict.dtd">
<html>
<head>
    <title>Down for maintenance</title>
    <link rel="stylesheet" type="text/css" href="templates/errors.css" />
</head>

<body>

<div id="message">

<h2>Database Error</h2>

<p>
<?php echo htmlentities($db->error) ?>
</p>

</div>

</body>
</html>