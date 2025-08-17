# hyperf-server-command（hyperf 3.1）

### 修改

* 适配3.1
* 未安装适配协程插件，不检查 swoole.use_shortname
* php bin/hyperf.php tmg:start -p 9501    //指定端口 默认查询name=http server port
* php bin/hyperf.php tmg:start -a 0.0.0.0 //监听地址 默认查找name=http server host
* php bin/hyperf.php tmg:start -w -i /bin/php //启动 watch 服务，参数 i(interpreter) 指定 php 安装目录

### 注意

该扩展包完全使用了FanchangWang写的代码，非常感谢FanchangWang，[FanchangWang的github地址](https://github.com/FanchangWang)     
我是在hyperf1.2的pr查看到的这份代码，应该已经被包含hyperf1.2的命令内了，具体请看该pr[地址](https://github.com/hyperf/hyperf/pull/1053)     

### 拉取包

```
composer require wayhood/hyperf-server-command
```

hyperf的启动、重启、停止、监听等命令如下：  

```
//php bin/hyperf.php tmg:start -d //启动服务并进入后台模式
//php bin/hyperf.php tmg:start -c //启动服务并清除 runtime/container 目录
//php bin/hyperf.php tmg:start -w //启动服务并监控 app、config目录以及 .env 变化自动重启
//php bin/hyperf.php tmg:start -w -p /bin/php //启动 watch 服务，参数 p 指定 php 安装目录
//php bin/hyperf.php tmg:start -w -t 10  //启动 watch 服务，参数 t 指定 watch 时间间隔，单位秒
//php bin/hyperf.php tmg:stop //停止服务
//php bin/hyperf.php tmg:restart //重启服务
//php bin/hyperf.php tmg:restart -c //重启服务并清除 runtime/container 目录
```

tmg的前缀是我这边默认加上去的，自行修改 
