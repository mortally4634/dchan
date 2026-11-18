<?php
/*
 * poll.php — example.com
 *
 * Serves the embedded poll iframe (vote form + results) for the poll system.
 * Ported from soyjakparty and adapted to IP-only voting (no pass/VPN system).
 * Tables are created on demand by imgboard_poll_tables().
 */
require 'inc/bootstrap.php';

global $config, $mod;
imgboard_poll_tables();

// CSRF: a vote or reveal must come from this site's own page. Browsers always send Origin on a
// cross-site POST, so a foreign Origin is refused (a missing one is a same-site form on older browsers).
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
	$origin_host = parse_url($_SERVER['HTTP_ORIGIN'] ?? '', PHP_URL_HOST);
	$my_host = parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
	if (!empty($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] !== 'null' && $origin_host !== $my_host)
		error('Cross-site request refused.');
}

if (isset($_POST['vote'])) {
	$poll_id = $_POST['poll-id'] ?? '';
	if (!is_numeric($poll_id)) error('Invalid poll ID.');
	$choices = $_POST['choices'] ?? null;
	if (!is_array($choices)) error('Invalid vote data.');
	vote_poll((int)$poll_id, $choices);
	header("Location: ?id=" . (int)$poll_id . "&results", true, 303);
	exit;
}

if (isset($_POST['reveal'])) {
	$poll_id = $_POST['poll-id'] ?? '';
	if (!is_numeric($poll_id)) error('Invalid poll ID.');
	$query = prepare("SELECT * FROM ``polls`` WHERE `poll_id` = :poll_id");
	$query->bindValue(':poll_id', (int)$poll_id, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	$poll = $query->fetch(PDO::FETCH_ASSOC);
	if (!$poll) error('Poll not found.');
	if ($_SERVER['REMOTE_ADDR'] != $poll['creator_ip']) error("You didn't create this poll.");
	$query = prepare("UPDATE ``polls`` SET `hide_results` = 0 WHERE `poll_id` = :poll_id");
	$query->bindValue(':poll_id', (int)$poll_id, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	header("Location: ?id=" . (int)$poll_id . "&results", true, 303);
	exit;
}

if (isset($_GET['id'])) {
	$poll_id = $_GET['id'];
	if (!is_numeric($poll_id)) error('Invalid poll ID.');
	$poll_id = (int)$poll_id;

	$query = prepare("SELECT * FROM ``polls`` WHERE `poll_id` = :poll_id");
	$query->bindValue(':poll_id', $poll_id, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	$poll = $query->fetch(PDO::FETCH_ASSOC);
	if (!$poll) error('Poll not found.');

	$query = prepare("SELECT * FROM ``poll_options`` WHERE `poll_id` = :poll_id");
	$query->bindValue(':poll_id', $poll_id, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	$options = $query->fetchAll(PDO::FETCH_ASSOC);

	if (isset($_GET['results'])) {
		$is_creator = $_SERVER['REMOTE_ADDR'] == $poll['creator_ip'];
		$total = 0;
		$voters = null;

		if (!$poll['hide_results'] || $is_creator || hasPermission($config['mod']['poll_snooping'])) {
			foreach ($options as &$option) {
				$q = prepare("SELECT COUNT(*) FROM ``poll_votes`` WHERE `option_id` = :oid AND `poll_id` = :pid");
				$q->bindValue(':pid', $poll_id, PDO::PARAM_INT);
				$q->bindValue(':oid', $option['option_id'], PDO::PARAM_INT);
				$q->execute() or error(db_error($q));
				$option['votes'] = $q->fetch(PDO::FETCH_NUM)[0];
				$total += $option['votes'];
			}
			unset($option);

			if ($poll['multiple_choice']) {
				$q = prepare("SELECT COUNT(DISTINCT `ip`) FROM ``poll_votes`` WHERE `poll_id` = :pid");
				$q->bindValue(':pid', $poll_id, PDO::PARAM_INT);
				$q->execute() or error(db_error($q));
				$voters = $q->fetch(PDO::FETCH_NUM)[0];
			}

			$votes = array_column($options, 'votes');
			if ($votes) array_multisort($votes, SORT_DESC, $options);
		}

		echo Element($config['file_poll_result'], array(
			'config'  => $config,
			'mod'     => $mod,
			'poll'    => $poll,
			'options' => $options,
			'creator' => $is_creator,
			'total'   => $total,
			'voters'  => $voters,
		));
	} else {
		$q = prepare("SELECT COUNT(*) FROM ``poll_votes`` WHERE `ip` = :ip AND `poll_id` = :pid");
		$q->bindValue(':pid', $poll_id, PDO::PARAM_INT);
		$q->bindValue(':ip', $_SERVER['REMOTE_ADDR']);
		$q->execute() or error(db_error($q));
		$voted = $q->fetch(PDO::FETCH_NUM)[0] != 0;

		echo Element($config['file_poll_vote'], array(
			'config'  => $config,
			'poll'    => $poll,
			'options' => $options,
			'voted'   => $voted,
		));
	}
} else {
	error('No poll provided.');
}
