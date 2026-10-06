<?php
require 'lib.php';

if(user()){
  header('Location: dashboard.php');
}else{
  if(cfg()){
    header('Location: login.php');
  }else{
    header('Location: setup.php');
  }
}
exit;
