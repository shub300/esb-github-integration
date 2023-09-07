
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title>{{env('APP_NAME')}}</title>
  <!-- Tell the browser to be responsive to screen width -->
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Font Awesome -->
  <link rel="stylesheet" href="{{asset('public/plugins/fontawesome-free/css/all.min.css')}}">
  <!-- Ionicons -->
  <link rel="stylesheet" href="https://code.ionicframework.com/ionicons/2.0.1/css/ionicons.min.css">
  <!-- Theme style -->
  <link rel="stylesheet" href="{{asset('public/dist/css/adminlte.min.css')}}">

  <!-- Google Font: Source Sans Pro -->
  <link href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700" rel="stylesheet">

  <style>
	  body, h3,h2,h1{
		  color:#000;
	  }
    .lockscreen-wrapper{
      margin-top: 8%;
    }
  </style>
</head>
<body class="hold-transition lockscreen style_3">

<div class="lockscreen-wrapper">
    <div class="lockscreen-logo">
        {{-- <img style="max-width: 80%" src="{{asset('public/img/apiworx/apiworx_main_logo.png')}}"/> --}}
    </div>
    <div class="container error-page">
        <h2 class="headline text-primary" style="text-align: center; margin-left:-15px;">404</h2>
        <div class="error-content">
            <h3><i class="fas fa-exclamation-triangle text-primary" style="padding-top: 20px"></i> Oops! Page not found.</h3>
            <p>
                We could not find the page you were looking for.
                Meanwhile, you may <a href="{{url('/integrations')}}">return to integrations.</a>
            </p>
        </div>
    </div>
</div>
<!-- /.center -->

<!-- jQuery -->
<script src="{{asset('public/plugins/jquery/jquery.min.js')}}"></script>
<!-- Bootstrap 4 -->
<script src="{{asset('public/plugins/bootstrap/js/bootstrap.bundle.min.js')}}"></script>
</body>
</html>
