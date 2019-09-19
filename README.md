

### phalcon-eagerload

> 允许phalcon进行数据预加载的扩展



## 安装

```bash
composer require sowork/phalcon-eagerload
```



## 使用
* 在项目中注册 `\Sowork\EagerLoad\EagerLoadServiceProvider::class`
* 在Model中引入 `Sowork\EagerLoad\Traits\EagerLoadingTrait` 文件，并定义相对的关联关系

  ```php
  <?php
  
  namespace Phalcon\Mvc\Model;
  use Sowork\EagerLoad\Traits\EagerLoadingTrait;
  
  /**
   * Class User
   * @package App\Models
   */
  class Book extends Model
  {
      use EagerLoadingTrait;
      public function getSource()
      {
          return 'users';
      }
  
      public function initialize()
      {
          parent::initialize();
          $this->belongsTo( 'id', App\Author::class,'bookId', [
              'alias' => 'blogs'
          ]);
      }
  }
  ```

  

* 使用with()方法对数据进行预加载

  ```php
  // 加载单个关联关系
  $books = App\Book::with('author')->findFirst([
      'conditions' => 'id = 10',
  ]);
  
  // 加载多个关联关系
  $books = App\Book::with('author', 'publisher')->find();
  
  // 加载嵌套关联关系
  $books = App\Book::with('author.contacts')->find();
  
  // 加载带条件约束的关联关系
  $users = App\User::with(['posts' => function ($query) {
      $query->where('title', 'like', '%first%');
  }])->find();
  ```



* 使用load()方法对数据进行懒惰渴求式加载
  ```php
    // 这在你需要动态决定是否加载关联模型时可能很有用
    $books = App\Book::find(); // or App\Book::findFirst()
    
    if ($someCondition) {
        $books->load('author', 'publisher');
    }
    
    // 也可以通过条件限制
  
    $books->load(['author' => function ($query) {
        $query->orderBy('published_date', 'asc');
    }]);

  ```


### 返回结果

* 根据列表或者对象，分别会返回 `Phalcon\Mvc\ModelInterface` 或 `Tightenco\Collect\Support\Collection` 对象

  

### 集合操作

> 当返回结果是一个集合时，更方便我们对数据结果进行处理，具体集合的操作方法请看[相关文档](https://xueyuanjun.com/post/19507.html)