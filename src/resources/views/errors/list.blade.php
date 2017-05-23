@if (count($errors) > 0)
    <div class="alert alert-danger" role="alert">
        <strong>Whoops!</strong> There were some problems with your input.<br><br>
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@elseif (Session::get('message'))
    <div class="alert alert-{{ Session::get('success') ? 'success' : 'danger' }}" role="alert">
        <p>{{ Session::get('message') }}</p>
    </div>
@endif
