<?php declare(strict_types=1);

/**
 * Effect envelope demo — the code-sample answer to "what would this look like?".
 *
 * Analyse with the effect-envelope branch (worktree effect-envelope):
 *   bin/phpstan analyse -l 8 --error-format=raw src/demo.php                      # default: stage 1-3 findings
 *   bin/phpstan analyse -l 8 --error-format=raw -c conf/bleedingEdge.neon src/demo.php  # + stage 4 (pure tolerates mutate.local)
 *
 * Analyse with any released PHPStan: every tag below parses cleanly and is
 * simply ignored - no behavior change, no parse error. That is the whole
 * backward-compatibility story in one file.
 */

namespace EffectEnvelopeDemo;

// ---------------------------------------------------------------------------
// 1. Abstractions carry envelopes: dependency injection no longer erases
//    effect information. (PSR-20 shape, the phpstan/phpstan#14220 example.)
// ---------------------------------------------------------------------------

interface ClockInterface
{
	/** @phpstan-impure nondet.time */
	public function now(): \DateTimeImmutable;
}

/** @phpstan-impure nondet.time */
final class SystemClock implements ClockInterface
{
	/** @phpstan-impure nondet.time */
	public function now(): \DateTimeImmutable
	{
		return new \DateTimeImmutable();
	}
}

interface UserRepository
{
	/** @phpstan-impure io.db */
	public function find(int $id): string;
}

// ---------------------------------------------------------------------------
// 2. A controller declares its operational shape. The body is checked against
//    the declaration (role B), and calls through the abstractions contribute
//    their declared labels (role A).
// ---------------------------------------------------------------------------

final class UserController
{
	public function __construct(
		private UserRepository $users,
		private ClockInterface $clock,
	)
	{
	}

	/**
	 * OK: everything the body does is subsumed by the envelope.
	 *
	 * @phpstan-impure io.db, nondet.time, io.output
	 */
	public function show(int $id): void
	{
		$user = $this->users->find($id);
		echo $user . ' @ ' . $this->clock->now()->format('c');
	}

	/**
	 * FINDING: the author claims "database only", but the body also prints.
	 * A refactor that adds echo (or a clock, or an HTTP call) to a method
	 * whose envelope does not cover it is caught at the declaration site.
	 *
	 * @phpstan-impure io.db
	 */
	public function export(int $id): string
	{
		$user = $this->users->find($id);
		echo 'exporting…';           // output exceeds the envelope
		return $user;
	}
}

// ---------------------------------------------------------------------------
// 3. Class-level envelopes: one tag bounds every method (PHPStan 2.1.39's
//    @phpstan-all-methods-impure, now with a parameter).
// ---------------------------------------------------------------------------

/** @phpstan-all-methods-impure io.net */
final class HttpMailer
{
	public function send(string $to): void
	{
		\fsockopen('mail.example.com', 25);  // constant host narrows to io.net - inside the envelope
	}

	public function sendAndLog(string $to): void
	{
		\fsockopen('mail.example.com', 25);
		\file_put_contents('/var/log/mail.log', $to);  // local literal narrows to io.fs.write - exceeds io.net
	}
}

// ---------------------------------------------------------------------------
// 4. Liskov: an implementation must not widen its abstraction's envelope.
//    (Runs under reportMethodPurityOverride / bleeding edge.)
// ---------------------------------------------------------------------------

interface Notifier
{
	/** @phpstan-impure io.net */
	public function notify(string $message): void;
}

final class FileLoggingNotifier implements Notifier
{
	/**
	 * FINDING (bleeding edge): io.fs.write widens the interface's io.net.
	 *
	 * @phpstan-impure io.net, io.fs.write
	 */
	public function notify(string $message): void
	{
		\file_put_contents('/var/log/notify.log', $message);
	}
}

// ---------------------------------------------------------------------------
// 5. The long-standing by-ref false positive, resolved: preg_match() fills a
//    local $matches without making slug() impure (bleeding edge). Writing
//    into a property instead is still caught - by the assignment machinery,
//    not by guessing at the call site.
// ---------------------------------------------------------------------------

/** @phpstan-pure */
function slug(string $value): string
{
	preg_match('/([a-z0-9-]+)/', strtolower($value), $matches);
	return $matches[1] ?? '';
}

/**
 * @phpstan-pure
 * @param list<int> $rows
 * @return list<int>
 */
function sortedCopy(array $rows): array
{
	sort($rows);        // mutates only the local copy - tolerated under pure
	return $rows;
}

final class MatchCache
{
	/** @var array<int, string> */
	private array $matches = [];

	/** @phpstan-pure */
	public function extract(string $value): string
	{
		preg_match('/(\d+)/', $value, $this->matches);   // still reported: escapes the frame
		return $this->matches[1] ?? '';
	}
}

// ---------------------------------------------------------------------------
// 6. Unknown labels never invent findings: this tag was written for a human
//    (or by an older tool) - the whole tag reads as today's bare @phpstan-impure.
// ---------------------------------------------------------------------------

/** @phpstan-impure legacy-database-stuff */
function migrate(): void
{
	echo 'migrating';    // no envelope finding: the tag is unbounded
	\file_put_contents('/tmp/x', 'y');
}

// ---------------------------------------------------------------------------
// 7. The flagship example from the proposal, end to end: builtins carry
//    labels through function metadata, so the clock read is a checked fact.
// ---------------------------------------------------------------------------

/**
 * OK: time() is nondet.time, the write is io.fs.write <= io - both declared.
 *
 * @phpstan-impure io, nondet.time (reads the clock for cache TTL)
 */
function refreshCache(string $key): int
{
	$expiry = \time() + 3600;
	\file_put_contents('/tmp/cache/' . $key, (string) $expiry);
	return $expiry;
}

/**
 * FINDING: the author claims "filesystem only", but the body reads the clock.
 *
 * @phpstan-impure io.fs
 */
function touchCache(): int
{
	$expiry = \time() + 3600;                            // nondet.time exceeds io.fs
	\file_put_contents('/tmp/cache/last', (string) $expiry);   // constant local path: io.fs.write, inside
	return $expiry;
}

// ---------------------------------------------------------------------------
// 8. Vocabulary diagnostics (bleeding edge): typos get a suggestion, human
//    notes stay silent, redundant labels are pointed out - all without ever
//    changing what the envelope means (an unknown label still reads as ⊤).
// ---------------------------------------------------------------------------

/** @phpstan-impure io.netw */
function typoedLabel(): void
{
	\curl_exec(\curl_init());   // FINDING on the tag: did you mean "io.net"? (the bound itself stays ⊤)
}

/** @phpstan-impure io, io.db */
function redundantLabel(): void
{
	\mysqli_real_query(new \mysqli(), 'SELECT 1');   // io.db is already covered by io
}

// ---------------------------------------------------------------------------
// 9. Possibly-grade findings: a labelled maybe-impure builtin is checked too.
//    mail() carries io from the catalogue (its transport is platform-defined);
//    whether it runs is not proven, but what it may do is declared - so the
//    finding says "may".
// ---------------------------------------------------------------------------

/** @phpstan-impure io.output */
function notifyAndPrint(string $to): void
{
	\mail($to, 'subject', 'body');   // FINDING: may have effect io
	echo 'sent';
}

// ---------------------------------------------------------------------------
// 10. The masking boundary, carried by the hierarchy: buffered output can be
//     captured by ob_start(), direct process-fd writes cannot. Declaring the
//     buffer-only envelope makes the difference checkable.
// ---------------------------------------------------------------------------

/** @phpstan-impure io.output.buffer */
function renderCapturable(string $s): void
{
	echo $s;                                    // buffer layer - inside the envelope
	\file_put_contents('php://output', $s);     // same mechanism as echo - inside
	\fwrite(STDOUT, $s);                        // FINDING: io.output.stdout, ob_start() never sees it
}
