A simple wrapper to search for ADJ number on EAMS system

## How to Install

The recommended way to install EAMS wrapper is through
[Composer](https://getcomposer.org/).

```bash
composer require codewisdoms/eams
```

# Example request

```php
$eams = new \CodeWisdoms\Eams\Eams();
$case_data = $eams->findByAdj([ADJ NUMBER HERE], ['case', 'events']);
```
