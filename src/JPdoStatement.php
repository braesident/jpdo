<?php

declare(strict_types=1);

namespace Braesident\JPdo;

use Braesident\Blowfish\Blowfish;
use PDOStatement;

final class JPdoStatement extends PDOStatement
{
  private array $_encCols = [];

  private array $duplicateParams = [];

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
    if (null !== $params) {
      $params = $this->expandDuplicateParams($params);
      $params = $this->encryptParams($params);
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

  public function setDuplicateParams(array $duplicateParams): self
  {
    $this->duplicateParams = $duplicateParams;

    return $this;
  }

  private function expandDuplicateParams(array $params): array
  {
    if (empty($this->duplicateParams)) {
      return $params;
    }

    foreach ($this->duplicateParams as $base => $duplicates) {
      $baseKey      = $base;
      $baseKeyColon = ':'.$base;
      $hasBase      = \array_key_exists($baseKey, $params);
      $hasBaseColon = \array_key_exists($baseKeyColon, $params);

      if ( ! $hasBase && ! $hasBaseColon) {
        continue;
      }

      $sourceKey = $hasBase ? $baseKey : $baseKeyColon;
      $prefix    = $hasBase ? '' : ':';
      $value     = $params[$sourceKey];

      foreach ($duplicates as $duplicate) {
        $dupKey = $prefix.$duplicate;
        $altKey = ':' === $prefix ? $duplicate : ':'.$duplicate;

        if ( ! \array_key_exists($dupKey, $params) && ! \array_key_exists($altKey, $params)) {
          $params[$dupKey] = $value;
        }
      }

      unset($params[$baseKey], $params[$baseKeyColon]);
    }

    return $params;
  }

  private function encryptParams(array $params): array
  {
    if (null === $this->blowfish || empty($this->_encCols)) {
      return $params;
    }

    foreach ($this->_encCols as $c) {
      $targets = [$c];

      if (isset($this->duplicateParams[$c])) {
        $targets = array_merge($targets, $this->duplicateParams[$c]);
      }

      foreach ($targets as $target) {
        if (\array_key_exists($target, $params) && ! empty($params[$target])) {
          $params[$target] = $this->blowfish->Encrypt($params[$target]);

          continue;
        }

        $colonTarget = ':'.$target;
        if (\array_key_exists($colonTarget, $params) && ! empty($params[$colonTarget])) {
          $params[$colonTarget] = $this->blowfish->Encrypt($params[$colonTarget]);
        }
      }
    }

    return $params;
  }
}
