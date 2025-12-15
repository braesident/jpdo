<?php

declare(strict_types=1);

namespace Braesident\JPdo;

use Braesident\Blowfish\Blowfish;
use PDOStatement;

final class JPdoStatement extends PDOStatement
{
  private array $_encCols = [];

  private ?Blowfish $blowfish;

  public function encrypt(array $columns, ?Blowfish $blowfish = null): self
  {
    if (null !== $blowfish) {
      $this->blowfish = $blowfish;
    }
    $this->_encCols = $columns;

    return $this;
  }

  public function execute(?array $params = null): bool
  {
    if (null !== $this->blowfish) {
      foreach ($this->_encCols as $c) {
        if (isset($params[$c]) && ! empty($params[$c])) {
          $params[$c] = $this->blowfish->Encrypt($params[$c]);
        } elseif (isset($params[':'.$c]) && ! empty($params[':'.$c])) {
          $params[':'.$c] = $this->blowfish->Encrypt($params[':'.$c]);
        }
      }
    }

    return parent::execute($params);
  }

  public function fetchDecrypted(?array $columns = null, int $mode = JPdo::FETCH_DEFAULT): mixed
  {
    $c = $columns ?? $this->_encCols;
    $r = parent::fetch($mode);

    foreach ($c as $col) {
      if (null === $r->{$col} || empty($r->{$col})) {
        continue;
      }

      $r->{$col} = trim($this->blowfish->Decrypt($r->{$col}));
    }

    return $r;
  }

  public function fetchObjectDecrypted(?array $columns = null, string $className = 'stdClass', array $ctorArgs = []): mixed
  {
    $c = $columns ?? $this->_encCols;
    $r = parent::fetchObject($className, $ctorArgs);

    if ( ! $r) {
      return $r;
    }

    foreach ($c as $col) {
      if ( ! isset($r->{$col}) || null === $r->{$col} || '' === $r->{$col}) {
        continue;
      }
      $r->{$col} = trim($this->blowfish->Decrypt($r->{$col}));
    }

    return $r;
  }

  public function fetchAllDecrypted(?array $columns = null, int $mode = JPdo::FETCH_DEFAULT, string $className = 'stdClass', array $ctorArgs = []): array
  {
    $c = $columns ?? $this->_encCols;

    $r = (JPdo::FETCH_CLASS === $mode || JPdo::FETCH_CLASS === $mode)
        ? parent::fetchAll($mode, $className, $ctorArgs)
        : parent::fetchAll($mode);

    foreach ($r as &$row) {
      if ($mode & JPdo::FETCH_GROUP) {
        foreach ($row as &$subrow) {
          foreach ($c as $col) {
            if ($mode & JPdo::FETCH_ASSOC) {
              if ( ! isset($subrow[$col]) || null === $subrow[$col] || '' === $subrow[$col]) {
                continue;
              }
              $subrow[$col] = trim($this->blowfish->Decrypt($subrow[$col]));
            } else {
              if ( ! isset($subrow->{$col}) || null === $subrow->{$col} || '' === $subrow->{$col}) {
                continue;
              }
              $subrow->{$col} = trim($this->blowfish->Decrypt($subrow->{$col}));
            }
          }
        }
      } else {
        foreach ($c as $col) {
          if ($mode & JPdo::FETCH_ASSOC) {
            if ( ! isset($row[$col]) || null === $row[$col] || '' === $row[$col]) {
              continue;
            }
            $row[$col] = trim($this->blowfish->Decrypt($row[$col]));
          } else {
            if ( ! isset($row->{$col}) || null === $row->{$col} || '' === $row->{$col}) {
              continue;
            }
            $row->{$col} = trim($this->blowfish->Decrypt($row->{$col}));
          }
        }
      }
    }
    unset($row);

    return $r;
  }

  public function setBlowfish(?Blowfish $blowfish): self
  {
    $this->blowfish = $blowfish;

    return $this;
  }
}
