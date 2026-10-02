<?php
$sql = file_get_contents('database_schema.sql');
// convert encoding if needed
$sql = mb_convert_encoding($sql, 'UTF-8', 'UTF-16LE');
preg_match_all('/CREATE TABLE `.*?` \([\s\S]*?ENGINE=InnoDB.*?;/', $sql, $matches);
$output = "<?php\n\$tables = [\n";
foreach ($matches[0] as $table) {
    $output .= "    \"" . addslashes(str_replace("\n", " ", $table)) . "\",\n";
}
$output .= "];\n?>";
file_put_contents('tables.php', $output);
echo "Extracted " . count($matches[0]) . " tables.";
?>
