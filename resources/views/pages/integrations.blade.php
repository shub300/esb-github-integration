@extends('layouts.master')

@section('head-content')

<style>

</style>

@endsection

@section('title', 'Integration')

@section('side-bar')
@include('layouts.menu-bar')
@endsection

@section('breadcrumb')
  <li class="breadcrumb-item active">Integration</li>
@endsection

@push('page-style')
	<style>
		.accoBtn{
			width: 25px;
			height: 25px;
			color: #6e6b7b;
			cursor:pointer;
			transition: transform .5s;
		}
		.setup-icon-box {
			padding-right:10px; 
		}
		.dhide{
			visibility: hidden;
		}
		
		.boxStyle1 img{max-width: 80%;}
		.boxStyle1:hover{    box-shadow: 0 4px 24px 0 rgb(94 80 238 / 50%);}
		.oneLineText{    white-space: nowrap;overflow: hidden;text-overflow: ellipsis;}
		.border-top-blue {
            border-top: 1px solid #c6c1f9!important;
        }
		.border-end-blue{border-right: 1px solid #c6c1f9!important;}
		
		
	</style>
@endpush

@section('page-content')


        <div class="row">
		   <div class="col-xl-3 col-md-4 col-sm-6">
			  <div class="card text-center boxStyle1">
				<div class="card-body">
				  <a href="step2"><img src="https://esb.apiworx.net/integration/public/img/shopify.png">
				  <h4 class="card-text oneLineText">Shopify</h4></a>
				</div>
			  </div>
			</div>
			<div class="col-xl-3 col-md-4 col-sm-6">
			  <div class="card text-center boxStyle1">
				<div class="card-body">
				  <a href="step2"><img src="https://esb.apiworx.net/integration/public/img/slack.png">
				  <h4 class="card-text oneLineText">slack</h4></a>
				</div>
			  </div>
			</div>
			<div class="col-xl-3 col-md-4 col-sm-6">
			  <div class="card text-center boxStyle1">
				<div class="card-body">
				  <a href="step2"><img src="https://esb.apiworx.net/integration/public/esb_asset/brand_icons/brightpearl.jpg">
				  <h4 class="card-text oneLineText">Brightpearl</h4></a>
				</div>
			  </div>
			</div>
			<div class="col-xl-3 col-md-4 col-sm-6">
			  <div class="card text-center boxStyle1">
				<div class="card-body">
				  <a href="step2"><img src="https://esb.apiworx.net/integration/public/img/woccomerce.png">
				  <h4 class="card-text oneLineText">Woocommerce</h4></a>
				</div>
			  </div>
			</div>
		</div>
		
		
		
			
			
			
@endsection
@push('page-script')
<script>

</script>
@endpush
