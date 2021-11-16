<?php declare(strict_types = 1);

namespace PHPStan\Parser;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeVisitorAbstract;
use PHPStan\Reflection\ParametersAcceptor;

class CleaningVisitor extends NodeVisitorAbstract
{

	private NodeFinder $nodeFinder;

	public function __construct()
	{
		$this->nodeFinder = new NodeFinder();
	}

	public function enterNode(Node $node): ?Node
	{
		if ($node instanceof Node\Stmt\Function_) {
			$node->stmts = $this->keepVariadicsAndYieldsAndInlineVars($node->stmts);
			return $node;
		}

		if ($node instanceof Node\Stmt\ClassMethod && $node->stmts !== null) {
			$node->stmts = $this->keepVariadicsAndYieldsAndInlineVars($node->stmts);
			return $node;
		}

		if ($node instanceof Node\Expr\Closure) {
			$node->stmts = $this->keepVariadicsAndYieldsAndInlineVars($node->stmts);
			return $node;
		}

		return null;
	}

	/**
	 * @param Node\Stmt[] $stmts
	 * @return Node\Stmt[]
	 */
	private function keepVariadicsAndYieldsAndInlineVars(array $stmts): array
	{
		$results = $this->nodeFinder->find($stmts, static function (Node $node): bool {
			if ($node instanceof Node\Expr\YieldFrom || $node instanceof Node\Expr\Yield_) {
				return true;
			}
			if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
				return in_array($node->name->toLowerString(), ParametersAcceptor::VARIADIC_FUNCTIONS, true);
			}
			if ($node instanceof Node\Stmt && $node->getDocComment() !== null) {
				return strpos($node->getDocComment()->getText(), '@var') !== false;
			}

			return false;
		});
		$newStmts = [];
		foreach ($results as $result) {
			if ($result instanceof Node\Expr\Yield_ || $result instanceof Node\Expr\YieldFrom) {
				$newStmts[] = new Node\Stmt\Expression($result);
				continue;
			}
			if ($result instanceof Node\Expr\FuncCall && $result->name instanceof Node\Name) {
				$newStmts[] = new Node\Stmt\Expression(new Node\Expr\FuncCall(new Node\Name\FullyQualified($result->name), [], $result->getAttributes()));
				continue;
			}

			$newStmts[] = new Node\Stmt\Nop($result->getAttributes());
		}

		return $newStmts;
	}

}
