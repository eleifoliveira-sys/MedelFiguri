public function rules(): array
{
    return [
            'title'    => ['required', 'string', 'max:255'],
            'author'   => ['required', 'string', 'max:255'],
            'color'    => ['required', 'bool'],
    ];
}